{{--
    Erinnerung an Rückrufe: beim Laden prüfen, danach jede Minute. Nur wenn in der letzten Minute
    Maus oder Tastatur benutzt wurden, sonst gleich bei der nächsten Eingabe. So hält die Erinnerung
    die Sitzung nicht offen, und die Abmeldung nach 120 Minuten Inaktivität bleibt.
--}}
<div
    wire:init="check"
    x-data="{
        active: false,
        missed: false,
        init() {
            setInterval(() => this.tick(), 60000)
        },
        seen() {
            this.active = true
            if (this.missed) {
                this.missed = false
                this.$wire.check()
            }
        },
        tick() {
            if (this.active && ! document.hidden) {
                this.$wire.check()
            } else {
                this.missed = true
            }
            this.active = false
        },
    }"
    x-on:keydown.window="seen()"
    x-on:pointerdown.window="seen()"
    x-on:pointermove.window.throttle.5000ms="seen()"
    x-on:wheel.window.throttle.5000ms="seen()"
    hidden
></div>
