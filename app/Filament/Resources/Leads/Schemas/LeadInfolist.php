<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\Lead;
use App\Support\Adk;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Vorgang')
                    ->columns(2)
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => Adk::statusLabel($state))
                            ->color(fn (string $state) => Adk::statusColor($state)),
                        TextEntry::make('close_reason')
                            ->label('Grund')
                            ->formatStateUsing(fn (?string $state) => config("adk.wrong_data_reasons.{$state}"))
                            ->visible(fn (Lead $record) => $record->close_reason !== null),
                        TextEntry::make('target_group')->label('Zielgruppe')->formatStateUsing(fn ($state) => Adk::targetGroupLabel($state)),
                        TextEntry::make('channel')->label('Eingangskanal')->formatStateUsing(fn ($state) => Adk::channelLabel($state)),
                        TextEntry::make('intake_ref')
                            ->label('Website-Vorgang')
                            ->visible(fn (Lead $record) => $record->intake_ref !== null),
                        TextEntry::make('next_action_at')
                            ->label('Wiedervorlage')
                            ->formatStateUsing(fn (Lead $record) => $record->nextActionLabel())
                            ->placeholder('–')
                            ->color(fn (Lead $record) => $record->isOverdue() || $record->isCallbackDue() ? 'danger' : null),
                        TextEntry::make('assignee.name')->label('zuständig')->placeholder('–'),
                        TextEntry::make('call_attempts')->label('Anrufversuche'),
                        TextEntry::make('last_contact_at')->label('letzter Kontakt')->dateTime('d.m.Y H:i')->placeholder('–'),
                        IconEntry::make('cross_selling')->label('Cross-Selling JB Design')->boolean(),
                        TextEntry::make('cross_selling_follow_up_at')->label('Wiedervorlage Cross-Selling')->date('d.m.Y')->placeholder('–'),
                        TextEntry::make('closed_at')->label('geschlossen am')->dateTime('d.m.Y H:i')->placeholder('offen'),
                        TextEntry::make('created_at')->label('angelegt am')->dateTime('d.m.Y H:i'),
                    ]),
                Section::make('Organisation')
                    ->columns(2)
                    ->columnSpan(1)
                    ->visible(fn (Lead $record) => $record->organization_id !== null)
                    ->headerActions([
                        Action::make('openOrganization')
                            ->label('Öffnen')
                            ->link()
                            ->url(fn (Lead $record) => OrganizationResource::getUrl('edit', ['record' => $record->organization_id])),
                    ])
                    ->schema([
                        TextEntry::make('organization.name')->label('Name')->columnSpanFull(),
                        TextEntry::make('organization.priority')->label('Priorität')->badge()->placeholder('–'),
                        TextEntry::make('organization.industry')->label('Branche')->placeholder('–'),
                        TextEntry::make('organization.street')->label('Straße')->placeholder('–'),
                        TextEntry::make('organization.city')
                            ->label('Ort')
                            ->formatStateUsing(fn (Lead $record) => trim($record->organization->postal_code.' '.$record->organization->city))
                            ->placeholder('–'),
                        TextEntry::make('organization.phone_display')
                            ->label('Telefon')
                            ->url(fn (Lead $record) => $record->organization?->phone_e164 ? 'tel:'.$record->organization->phone_e164 : null)
                            ->placeholder('–'),
                        TextEntry::make('organization.email')->label('E-Mail')->placeholder('–'),
                        TextEntry::make('organization.website')->label('Website')->placeholder('–'),
                        TextEntry::make('organization.employee_count')->label('Mitarbeitende')->placeholder('–'),
                        TextEntry::make('organization.source')->label('Quelle'),
                        TextEntry::make('organization.retrieved_at')->label('Abrufdatum')->date('d.m.Y'),
                        TextEntry::make('organization.source_url')
                            ->label('Fundstelle')
                            ->url(fn (Lead $record) => $record->organization?->source_url, shouldOpenInNewTab: true)
                            ->limit(40)
                            ->placeholder('–')
                            ->columnSpanFull(),
                    ]),
                Section::make('Kontakt')
                    ->columns(2)
                    ->columnSpan(1)
                    ->visible(fn (Lead $record) => $record->contact_id !== null)
                    ->headerActions([
                        Action::make('openContact')
                            ->label('Öffnen')
                            ->link()
                            ->url(fn (Lead $record) => ContactResource::getUrl('edit', ['record' => $record->contact_id])),
                    ])
                    ->schema([
                        TextEntry::make('contact_name')->label('Name')->state(fn (Lead $record) => $record->contact?->fullName())->columnSpanFull(),
                        TextEntry::make('contact.position')->label('Funktion')->placeholder('–'),
                        IconEntry::make('contact.is_private')->label('Privatperson')->boolean(),
                        TextEntry::make('contact.phone_display')
                            ->label('Telefon')
                            ->url(fn (Lead $record) => $record->contact?->phone_e164 && $record->isCallable() ? 'tel:'.$record->contact->phone_e164 : null)
                            ->placeholder('–'),
                        TextEntry::make('contact.email')->label('E-Mail')->placeholder('–'),
                        IconEntry::make('contact.phone_refused')
                            ->label('Kein Anruf gewünscht')
                            ->boolean()
                            ->trueColor('danger')
                            ->falseColor('gray'),
                        TextEntry::make('contact.privacy_notice_sent_at')->label('Datenschutzhinweis übermittelt am')->date('d.m.Y')->placeholder('noch nicht'),
                        TextEntry::make('contact.phone_consent_at')->label('Einwilligung Telefonansprache am')->date('d.m.Y')->placeholder('keine'),
                        TextEntry::make('contact.phone_consent_proof')->label('Nachweis Einwilligung Telefon')->placeholder('–')->columnSpanFull(),
                        TextEntry::make('contact.email_consent_at')->label('Einwilligung Kontakt per E-Mail am')->date('d.m.Y')->placeholder('keine'),
                        TextEntry::make('contact.email_consent_proof')->label('Nachweis Einwilligung E-Mail')->placeholder('–')->columnSpanFull(),
                        TextEntry::make('contact.health_consent_at')
                            ->label('Einwilligung Gesundheitsangaben am')
                            ->date('d.m.Y')
                            ->placeholder('keine')
                            ->visible(fn (Lead $record) => $record->target_group === 'E'),
                    ]),
            ]),
            TextEntry::make('call_block')
                ->hiddenLabel()
                ->state(fn (Lead $record) => $record->callBlockReason())
                ->visible(fn (Lead $record) => $record->isOpen() && $record->callBlockReason() !== null)
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle')
                ->columnSpanFull(),
        ]);
    }
}
