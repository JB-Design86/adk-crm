<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadExportService;
use App\Support\Adk;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization', 'contact', 'assignee']))
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('CASE WHEN status = ? AND channel IN ('.implode(',', array_fill(0, count(Adk::inboundChannels()), '?')).') THEN 0 ELSE 1 END', ['new', ...Adk::inboundChannels()])
                ->orderByRaw('next_action_at IS NULL')
                ->orderBy('next_action_at'))
            ->recordClasses(fn (Lead $record) => $record->isNewInbound() ? 'adk-row-inbound' : null)
            ->columns([
                TextColumn::make('name')
                    ->label('Vorgang')
                    ->state(fn (Lead $record) => $record->displayName())
                    ->description(fn (Lead $record) => $record->organization && $record->contact ? $record->contact->fullName() : null)
                    ->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->whereHas('organization', fn (Builder $o) => $o->where('name', 'like', "%{$search}%")->orWhere('city', 'like', "%{$search}%")->orWhere('postal_code', 'like', "{$search}%"))
                        ->orWhereHas('contact', fn (Builder $c) => $c->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Adk::statusLabel($state))
                    ->color(fn (string $state) => Adk::statusColor($state))
                    ->sortable(),
                TextColumn::make('organization.priority')->label('Prio')->badge()->color('gray'),
                TextColumn::make('target_group')->label('Zielgruppe')->formatStateUsing(fn ($state) => Adk::targetGroupLabel($state))->toggleable(),
                TextColumn::make('channel')->label('Kanal')->formatStateUsing(fn ($state) => Adk::channelLabel($state))->toggleable(),
                TextColumn::make('organization.industry')->label('Branche')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('organization.city')->label('Ort')->toggleable(),
                TextColumn::make('next_action_at')
                    ->label('Wiedervorlage')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Lead $record) => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (Lead $record) => $record->isOverdue() ? 'bold' : null),
                TextColumn::make('call_attempts')->label('Versuche')->alignCenter()->toggleable(),
                IconColumn::make('cross_selling')->label('JB')->boolean()->trueIcon(Heroicon::OutlinedSparkles)->falseIcon('')->tooltip('Cross-Selling JB Design'),
                TextColumn::make('assignee.name')->label('zuständig')->toggleable(),
                TextColumn::make('last_contact_at')->label('letzter Kontakt')->dateTime('d.m.Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(Adk::statusOptions())->multiple(),
                SelectFilter::make('target_group')->label('Zielgruppe')->options(Adk::targetGroupOptions())->multiple(),
                SelectFilter::make('channel')->label('Eingangskanal')->options(Adk::channelOptions())->multiple(),
                SelectFilter::make('priority')
                    ->label('Priorität')
                    ->options(Adk::priorityOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? null, fn ($q, $values) => $q->whereHas('organization', fn ($o) => $o->whereIn('priority', $values)))),
                SelectFilter::make('industry')
                    ->label('Branche')
                    ->options(Adk::industryOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? null, fn ($q, $values) => $q->whereHas('organization', fn ($o) => $o->whereIn('industry', $values)))),
                SelectFilter::make('assigned_to')
                    ->label('zuständig')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Filter::make('mine')
                    ->label('nur meine')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
                TernaryFilter::make('cross_selling')->label('Cross-Selling JB Design'),
                Filter::make('next_action_at')
                    ->label('Wiedervorlage')
                    ->schema([
                        DatePicker::make('from')->label('Wiedervorlage von'),
                        DatePicker::make('until')->label('Wiedervorlage bis'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('next_action_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('next_action_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Wiedervorlage ab '.Carbon::parse($data['from'])->format('d.m.Y') : null,
                        ($data['until'] ?? null) ? 'Wiedervorlage bis '.Carbon::parse($data['until'])->format('d.m.Y') : null,
                    ])),
            ])
            ->filtersFormColumns(3)
            ->persistFiltersInSession()
            ->headerActions([
                Action::make('export')
                    ->label('Exportieren')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn () => Gate::allows('export'))
                    ->modalHeading('Gefilterte Vorgangsliste exportieren')
                    ->modalDescription('Exportiert werden alle Vorgänge, die den aktuellen Filtern und der Suche entsprechen. Der Export wird protokolliert.')
                    ->modalSubmitActionLabel('Herunterladen')
                    ->schema([
                        Select::make('format')
                            ->label('Format')
                            ->options(['xlsx' => 'Excel (XLSX)', 'csv' => 'CSV (Semikolon)'])
                            ->default('xlsx')
                            ->required(),
                    ])
                    ->action(function (array $data, HasTable $livewire) {
                        $path = app(LeadExportService::class)->export(
                            $livewire->getFilteredSortedTableQuery(),
                            $data['format'],
                            ['filters' => $livewire->tableFilters, 'search' => $livewire->tableSearch],
                            auth()->user(),
                        );

                        return response()->download($path, 'vorgaenge_'.now()->format('Y-m-d_His').'.'.$data['format'])->deleteFileAfterSend();
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Öffnen'),
            ])
            ->recordUrl(fn (Lead $record) => LeadResource::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }
}
