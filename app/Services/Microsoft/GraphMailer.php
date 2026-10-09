<?php

namespace App\Services\Microsoft;

use App\Models\MailConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Versand über Microsoft Graph (POST /me/sendMail) aus dem Postfach der verbundenen Person.
 * Die Mail liegt danach in Outlook unter „Gesendete Elemente“, Antworten kommen dort an.
 */
class GraphMailer
{
    /** Anhänge zusammen: Graph nimmt in einem sendMail-Aufruf höchstens etwa 4 MB an, Base64 macht Dateien ein Drittel größer. */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    /** Eigene Kopfzeile mit Kennung, damit sich die Mail später (Antworten ins CRM) in „Gesendete Elemente“ wiederfinden lässt. */
    public const REFERENCE_HEADER = 'x-adk-crm-ref';

    /** Content-ID des Logos in der Signatur (<img src="cid:…">). */
    public const LOGO_CID = 'adk-signatur-logo';

    public function __construct(private MicrosoftClient $client) {}

    /**
     * Sendet den Text als HTML, darunter die Signatur und das Logo.
     *
     * @param  string  $html  bereits bereinigtes HTML (MailHtml::sanitize)
     * @param  list<array{name: string, content_type: string, contents: string}>  $attachments
     * @return string Kennung aus der Kopfzeile x-adk-crm-ref (Graph liefert beim Senden keine Nachrichten-ID)
     *
     * @throws RuntimeException mit einer Meldung für die Oberfläche
     */
    public function sendMail(MailConnection $connection, string $to, string $subject, string $html, array $attachments = []): string
    {
        // Logo der Signatur als eingebettetes Bild (cid:), so erscheint es ohne „Bilder herunterladen“.
        $logo = $connection->logo();
        $logoTag = $logo ? '<img src="cid:'.self::LOGO_CID.'" alt="Logo" width="'.$connection->logoWidth().'" style="display:block;border:0;margin-top:12px">' : null;

        $size = array_sum(array_map(fn (array $file) => strlen($file['contents']), $attachments)) + strlen($logo['contents'] ?? '');

        // Grenze für alle Anhänge zusammen, das Logo der Signatur eingeschlossen.
        if ($size > self::MAX_ATTACHMENT_BYTES) {
            $megabytes = number_format($size / 1048576, 1, ',', '.').' MB, höchstens 3 MB';

            throw new RuntimeException(count($attachments) > 1
                ? 'Die Anhänge sind zusammen zu groß ('.$megabytes.'). Microsoft 365 nimmt beim direkten Versand keine größeren Anhänge an. Bitte Dateien weglassen, verkleinern oder als Link senden.'
                : 'Der Anhang ist zu groß ('.$megabytes.'). Microsoft 365 nimmt beim direkten Versand keine größeren Anhänge an. Bitte die Datei verkleinern oder als Link senden.');
        }

        $reference = (string) Str::uuid();

        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => self::html($html, $connection->signatureHtml(), $logoTag)],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
            'internetMessageHeaders' => [['name' => self::REFERENCE_HEADER, 'value' => $reference]],
        ];

        $files = array_map(fn (array $file) => [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $file['name'],
            'contentType' => $file['content_type'],
            'contentBytes' => base64_encode($file['contents']),
        ], $attachments);

        if ($logo) {
            $files[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $logo['name'],
                'contentType' => $logo['content_type'],
                'contentBytes' => base64_encode($logo['contents']),
                'isInline' => true,
                'contentId' => self::LOGO_CID,
            ];
        }

        if ($files !== []) {
            $message['attachments'] = $files;
        }

        try {
            $response = $this->client->request($connection)->post($this->client->graph('/me/sendMail'), [
                'message' => $message,
                'saveToSentItems' => true,
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Microsoft 365 ist gerade nicht erreichbar. Die E-Mail wurde nicht gesendet. Bitte versuchen Sie es in einigen Minuten erneut.');
        }

        if ($response->failed()) {
            throw $this->rejected($response);
        }

        return $reference;
    }

    /**
     * Ganze E-Mail: Text (bereinigtes HTML aus MailHtml::sanitize, Absätze mit Abstand darunter),
     * danach die Signatur, die ebenfalls bereinigt kommt (MailConnection::signatureHtml()), und das Logo.
     */
    public static function html(string $body, ?string $signatureHtml = null, ?string $logoTag = null): string
    {
        $html = trim($body);

        if (filled(trim((string) $signatureHtml)) || filled($logoTag)) {
            $html .= "\n".$signatureHtml.$logoTag;
        }

        return '<div style="font-family: Calibri, Arial, Helvetica, sans-serif; font-size: 11pt;">'.$html.'</div>';
    }

    /** Ablehnung mit Status und Begründung von Microsoft, damit sich der Fehler ohne Serverprotokoll eingrenzen lässt. */
    private function rejected(Response $response): RuntimeException
    {
        $reason = $response->json('error.message') ?? $response->json('error_description') ?? trim(strip_tags($response->body()));
        $reason = Str::limit((string) (is_scalar($reason) ? $reason : json_encode($reason)), 300);

        return new RuntimeException(match ($response->status()) {
            401 => 'Microsoft 365 hat die Anmeldung abgelehnt (401). '.MicrosoftClient::RECONNECT,
            403 => trim('Microsoft 365 erlaubt den Versand aus Ihrem Postfach nicht (403). '.MicrosoftClient::RECONNECT.' '.$reason),
            413 => 'Die E-Mail ist für Microsoft 365 zu groß (413). Bitte den Anhang verkleinern oder als Link senden.',
            429 => 'Microsoft 365 meldet zu viele E-Mails in kurzer Zeit (429). Bitte versuchen Sie es in einigen Minuten erneut.',
            default => trim('Microsoft 365 hat die E-Mail nicht angenommen ('.$response->status().'). '.$reason),
        });
    }
}
