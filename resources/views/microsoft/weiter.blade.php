<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    {{-- Weiterleitung innerhalb des CRM, damit der Browser das Sitzungscookie mitschickt (MicrosoftController::bounce). --}}
    <meta http-equiv="refresh" content="0;url={{ $target }}">
    <title>Weiter zum CRM</title>
</head>
<body style="font-family: system-ui, sans-serif; margin: 3rem auto; max-width: 32rem; padding: 0 1rem; color: #111;">
    <p>Die Anmeldung bei Microsoft ist abgeschlossen.</p>
    <p><a href="{{ $target }}">Weiter zum CRM</a></p>
</body>
</html>
