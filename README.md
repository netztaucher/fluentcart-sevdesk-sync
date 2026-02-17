# FluentCart → sevDesk Sync

Synchronisiert Bestellungen aus dem **FluentCart**‑Plugin automatisch nach sevDesk: legt Kontakte mit Adressen/Kommunikationswegen an und erstellt Rechnungsentwürfe inklusive Positionen.

## Features
- Kontakt‑Upsert per E‑Mail oder vorhandener Rechnungsreferenz, inkl. Kundennummer, Zahlungsziel, Adresse, E‑Mail/Telefon.
- Rechnungsentwurf (Status 100) mit Positionsübernahme aus FluentCart, Währung & Steuer (TaxRule 1).
- Idempotenz via `_sevdesk_invoice_id` Post‑Meta, erneutes Senden löscht keine Rechnungen.

## Voraussetzungen
- WordPress mit dem FluentCart‑Plugin.
- sevDesk API‑Token (32‑stelliger Hex‑String) eines Admin‑Nutzers.
- PHP 8.1+, Composer‑Dependencies im Ordner `vendor/` (bereits enthalten).

## Installation
1. Plugin‑Ordner `fluentcart-sevdesk-sync` in `wp-content/plugins/` legen.
2. Aktivieren im WordPress‑Backend.
3. API‑Key im Code hinterlegen (derzeit direkt im Aufrufer, z.B. bei Instanziierung `new \FcSevdesk\Sync('API_KEY')`). Optional: in eine Option/ENV auslagern.

## Nutzung
- Hook: `fluent_cart/order_created` (wird intern registriert) triggert Sync.
- Manuell: `php -r "require 'wp-load.php'; $o=FluentCart\App\Models\Order::find(ID); (new \FcSevdesk\Sync('API_KEY'))->pushOrder($o);"`.
- Kontakt‑Only: `syncContactOnly($order)` aktualisiert nur Stammdaten/Adresse/Kommunikationswege.

## Konfiguration & Mapping (wichtigste Felder)
- Name: Firma oder Vor‑/Nachname; Status: 1000; Kategorie: 1 (Kunde).
- Adresse: billing address → ContactAddress (Category 1, StaticCountry Lookup).
- Kommunikationswege: E‑Mail (key 2, main), Telefon (key 2), Mobil (key 4) sofern vorhanden.
- Rechnung: Datum = order `created_at`, Nummer = `invoice_no|receipt_number`, Zahlungsziel 14 Tage, TaxRule 1, Steuer 19% Fallback.

## Entwicklertipps
- Idempotenz: `_sevdesk_invoice_id` Meta löschen, um erneut zu senden.
- Country Lookup cacht StaticCountry per ISO‑Code, Fallback DE.
- API‑Calls via itsmind/sevdesk-php-sdk; HTTP Fallbacks direkt per Guzzle.

## Bekannte Einschränkungen
- Kein UI zur Eingabe des API‑Keys; Anpassung im Aufrufer nötig.
- Unterstützt derzeit nur Standard‑TaxRule (1) und Rechnungsentwürfe.
- Keine Produktanlage in sevDesk (nur Positionsübernahme als Freitext).

## Lizenz
Projektspezifisch; falls erforderlich, Lizenz ergänzen.
