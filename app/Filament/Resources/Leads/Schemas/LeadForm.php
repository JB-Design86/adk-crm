<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Organizations\Schemas\OrganizationForm;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Duplicates\DuplicateFinder;
use App\Support\Adk;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vorgang')
                ->description('Der Status wird über „Status setzen“ oder die Anrufliste geändert, damit die Regeln greifen.')
                ->columns(2)
                ->schema([
                    Select::make('organization_id')
                        ->label('Organisation')
                        ->relationship('organization', 'name')
                        ->searchable()
                        ->preload(false)
                        ->live()
                        ->createOptionForm(OrganizationForm::fields())
                        ->createOptionUsing(function (array $data) {
                            $organization = Organization::create($data);
                            app(DuplicateFinder::class)->record($organization);

                            return $organization->getKey();
                        })
                        ->requiredWithout('contact_id'),
                    Select::make('contact_id')
                        ->label('Kontakt')
                        ->relationship(
                            'contact',
                            'last_name',
                            fn (Builder $query, Get $get) => $query->when($get('organization_id'), fn ($q, $organizationId) => $q->where('organization_id', $organizationId)),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Contact $record) => $record->fullName())
                        ->searchable(['first_name', 'last_name', 'email'])
                        ->createOptionForm(ContactForm::fields())
                        ->createOptionUsing(function (array $data, Get $get) {
                            $contact = Contact::create([...$data, 'organization_id' => $get('organization_id')]);
                            app(DuplicateFinder::class)->record($contact);

                            return $contact->getKey();
                        })
                        ->requiredWithout('organization_id'),
                    Select::make('target_group')
                        ->label('Zielgruppe')
                        ->options(Adk::targetGroupOptions())
                        ->default('company_open')
                        ->required(),
                    Select::make('channel')
                        ->label('Eingangskanal')
                        ->options(Adk::channelOptions())
                        ->required(),
                    Select::make('assigned_to')
                        ->label('zuständig')
                        ->options(fn () => User::where('is_blocked', false)->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => auth()->id()),
                    Grid::make(2)->schema([
                        DatePicker::make('next_action_at')
                            ->label('nächste Aktion am')
                            ->helperText('Bei eingehenden Anfragen automatisch heute.'),
                        TimePicker::make('next_action_time')
                            ->label('Uhrzeit (optional)')
                            ->seconds(false)
                            ->helperText('Für einen Rückruf zu einer festen Zeit, nur zusammen mit dem Datum.'),
                    ]),
                ]),
        ]);
    }
}
