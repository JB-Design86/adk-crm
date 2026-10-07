<?php

namespace App\Filament\Pages;

use App\Filament\Actions\LeadActions;
use App\Filament\Concerns\ListensForLeadRest;
use App\Filament\Resources\FundingCases\FundingCaseResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Appointment;
use App\Models\Lead;
use App\Support\Adk;
use App\Support\Hilfe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Startseite „Heute“: offene Vorgänge mit Wiedervorlage heute oder überfällig,
 * dazu die Termine des Tages. Neue eingehende Anfragen rot ganz oben, danach fällige Rückrufe mit Uhrzeit.
 */
class Today extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use ListensForLeadRest;

    protected string $view = 'filament.pages.today';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Heute';

    public bool $onlyMine = false;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('leads.view');
    }

    public function getSubheading(): string
    {
        return now()->locale('de')->isoFormat('dddd, D. MMMM YYYY').' · '.Hilfe::seite('heute');
    }

    public function table(Table $table): Table
    {
        $inbound = Adk::inboundChannels();
        $placeholders = implode(',', array_fill(0, count($inbound), '?'));

        return $table
            ->query(fn () => Lead::query()
                ->with(['organization', 'contact', 'assignee', 'fundingCase'])
                ->whereNull('closed_at')
                ->where(fn (Builder $q) => $q
                    ->whereDate('next_action_at', '<=', today())
                    ->orWhere(fn (Builder $n) => $n->where('status', 'new')->whereIn('channel', $inbound))
                    ->orWhere(fn (Builder $c) => $c->where('cross_selling', true)->whereDate('cross_selling_follow_up_at', '<=', today())))
                ->orderByRaw("CASE WHEN status = 'new' AND channel IN ({$placeholders}) THEN 0 ELSE 1 END", $inbound)
                ->orderByCallbackDue()
                ->orderBy('next_action_at')
                // Am selben Tag: Rückrufe mit Uhrzeit vor denen ohne, nach Uhrzeit.
                ->orderByRaw('next_action_time IS NULL')
                ->orderBy('next_action_time')
                ->orderBy('id'))
            ->heading('Wiedervorlagen')
            ->description('Heute fällig und überfällig. Neue eingehende Anfragen stehen rot oben, danach fällige Rückrufe mit Uhrzeit.')
            ->recordClasses(fn (Lead $record) => $record->isNewInbound() ? 'adk-row-inbound' : null)
            ->columns([
                TextColumn::make('name')
                    ->label('Vorgang')
                    ->state(fn (Lead $record) => $record->displayName())
                    ->description(fn (Lead $record) => match (true) {
                        $record->isNewInbound() => 'Neue Anfrage: '.Adk::channelLabel($record->channel),
                        $record->fundingCase?->isOpen() => 'Förderweg: '.($record->fundingCase->currentStep()?->name ?? 'Einschreibung bestätigen'),
                        default => $record->organization && $record->contact ? $record->contact->fullName() : null,
                    })
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Adk::statusLabel($state))
                    ->color(fn (string $state) => Adk::statusColor($state)),
                TextColumn::make('organization.priority')->label('Prio')->badge()->color('gray'),
                TextColumn::make('phone')
                    ->label('Telefon')
                    ->state(fn (Lead $record) => $record->phoneDisplay())
                    ->url(fn (Lead $record) => $record->isCallable() ? 'tel:'.$record->phoneE164() : null)
                    ->color(fn (Lead $record) => $record->isCallable() ? 'primary' : 'gray')
                    ->tooltip(fn (Lead $record) => $record->callBlockReason()),
                TextColumn::make('next_action_at')
                    ->label('Wiedervorlage')
                    ->formatStateUsing(fn (Lead $record) => $record->nextActionLabel())
                    ->description(fn (Lead $record) => match (true) {
                        $record->isCallbackDue() => 'Rückruf jetzt fällig',
                        $record->next_action_time !== null && $record->next_action_at?->isToday() => "Rückruf um {$record->next_action_time} Uhr",
                        $record->isOverdue() => 'überfällig',
                        default => null,
                    })
                    ->color(fn (Lead $record) => $record->isOverdue() || $record->isCallbackDue() ? 'danger' : null)
                    ->weight(fn (Lead $record) => $record->isOverdue() || $record->isCallbackDue() ? 'bold' : null),
                IconColumn::make('cross_selling_due')
                    ->label('JB')
                    ->state(fn (Lead $record) => $record->cross_selling && $record->cross_selling_follow_up_at?->lte(today()))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedSparkles)
                    ->falseIcon('')
                    ->tooltip('Cross-Selling JB Design fällig'),
                TextColumn::make('assignee.name')->label('zuständig')->placeholder('–'),
            ])
            ->filters([
                Filter::make('mine')
                    ->label('nur meine')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            ])
            ->recordActions([
                LeadActions::setStatus(),
                Action::make('call')
                    ->label('Anrufliste')
                    ->icon(Heroicon::OutlinedPhone)
                    ->color('gray')
                    ->visible(fn (Lead $record) => Gate::allows('call_list') && $record->isCallable())
                    ->url(fn (Lead $record) => CallList::getUrl(['vorgang' => $record->id])),
            ])
            ->recordUrl(fn (Lead $record) => $record->fundingCase?->isOpen()
                ? FundingCaseResource::getUrl('view', ['record' => $record->fundingCase])
                : LeadResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Heute ist nichts fällig.')
            ->paginated([25, 50, 100]);
    }

    /** @return Collection<int, Appointment> */
    #[Computed]
    public function appointments(): Collection
    {
        return Appointment::query()
            ->with(['lead.organization', 'lead.contact', 'user'])
            ->whereDate('starts_at', today())
            ->when($this->onlyMine, fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->orderBy('starts_at')
            ->get();
    }
}
