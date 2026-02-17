<?php

namespace FcSevdesk;

use Itsmind\Sevdesk\Configuration;
use Itsmind\Sevdesk\Api\ContactApi;
use Itsmind\Sevdesk\Api\InvoiceApi;
use Itsmind\Sevdesk\Api\InvoicePositionApi;
use Itsmind\Sevdesk\Api\SevUserApi;
use Itsmind\Sevdesk\Api\ContactApi as ContactApiFind;
use Itsmind\Sevdesk\Model\ModelContact;
use Itsmind\Sevdesk\Model\ModelContactUpdate;
use Itsmind\Sevdesk\Model\ModelContactAddress;
use Itsmind\Sevdesk\Model\ModelInvoice;
use Itsmind\Sevdesk\Model\ModelInvoicePosition;
use GuzzleHttp\Client;

class Sync
{
    protected $apiKey;
    protected $config;
    protected $http;
    protected $defaultContactPersonId;
    protected $countryCache = [];

    public function __construct( string $apiKey )
    {
        $this->apiKey = $apiKey;
        $this->config = Configuration::getDefaultConfiguration()
            ->setApiKey( 'Authorization', $apiKey );
        $this->http = new Client();
    }

    public function pushOrder( $order )
    {
        // Idempotenz: schon gesendet?
        $sentId = (int) get_post_meta( $order->id, '_sevdesk_invoice_id', true );
        if ( $sentId ) {
            return $sentId;
        }

        // Kontakt anlegen/finden
        $contactId = $this->upsertContact( $order );

        // Rechnung inkl. Positionen erstellen (Entwurf status 100)
        $invoiceId = $this->createInvoice( $order, $contactId );

        update_post_meta( $order->id, '_sevdesk_invoice_id', $invoiceId );

        if ( method_exists( $order, 'addNote' ) ) {
            $order->addNote( 'sevDesk: Rechnung #' . $invoiceId . ' erstellt.' );
        }

        return $invoiceId;
    }

    protected function upsertContact( $order )
    {
        $contactApi = new ContactApi( $this->http, $this->config );

        // Falls bereits eine sevDesk-Rechnung existiert, hole deren Contact-ID
        $existingId = null;
        $invoiceIdMeta = (int) get_post_meta( $order->id, '_sevdesk_invoice_id', true );
        if ( $invoiceIdMeta ) {
            try {
                $host = rtrim( $this->config->getHost(), '/' );
                $resp = $this->http->request( 'GET', $host . '/Invoice/' . $invoiceIdMeta . '?embed=contact', [
                    'headers' => [ 'Authorization' => $this->apiKey ],
                ] );
                $json = json_decode( (string) $resp->getBody(), true );
                $contactId = $json['objects'][0]['contact']['id'] ?? null;
                if ( $contactId ) {
                    $existingId = $contactId;
                }
            } catch ( \Throwable $e ) {}
        }

        // Prüfe, ob ein Contact mit gleicher E-Mail existiert
        if ( ! $existingId ) {
            $existingId = $this->findContactByEmail( $order->billing_address->email ?? $order->customer_email ?? '' );
        }

        $billing = $order->billing_address ?? (object) [];
        $company = $billing->company ?? ( $order->billing_company ?? null );
        $first   = $order->billing_first_name ?: ( $billing->first_name ?? '' );
        $last    = $order->billing_last_name  ?: ( $billing->last_name  ?? '' );
        $vat     = $order->billing_vat_number ?? ( $billing->vat_number ?? null );
        $taxNo   = $order->billing_tax_number ?? ( $billing->tax_number ?? null );
        $timeToPay = $order->payment_deadline_days ?? 14;

        // sevDesk verlangt oft eine laufende Kundennummer – wir holen uns die nächste freie
        $nextNumber = null;
        try {
            $nextNumberResponse = $contactApi->getNextCustomerNumber();
            // Die SDK-Response liefert die Nummer im Feld "objects"
            $nextNumber = $nextNumberResponse->getObjects();
        } catch ( \Throwable $e ) {
            // wenn es fehlschlägt, lassen wir customerNumber leer und hoffen auf auto Vergabe
        }

        $contactPayload = [
            'object_name'         => 'Contact',
            'map_all'             => true,
            'status'              => 1000, // aktiv
            'surename'            => $first,
            'familyname'          => $last,
            'name'                => $company ?: $this->resolveName( $order ),
            'customer_number'     => $nextNumber,
            'category'            => [ 'id' => 1, 'objectName' => 'Category' ],
            'description'         => $billing->order_note ?? null,
            'vat_number'          => $vat,
            'tax_number'          => $taxNo,
            'default_time_to_pay' => $timeToPay,
        ];

        if ( $existingId ) {
            // Update vorhandenen Kontakt mit neuen Stammdaten
            try {
                $update = new \Itsmind\Sevdesk\Model\ModelContactUpdate( $this->filterPayload( $contactPayload ) );
                $contactApi->updateContact( $existingId, $update );
            } catch ( \Throwable $e ) {}
            $contactId = $existingId;
        } else {
            $contact = new ModelContact( $this->filterPayload( $contactPayload ) );
            $created = $contactApi->createContact( $contact );
            // API antwortet mit CreateContact201Response -> objects enthält den Kontakt
            $contactObj = method_exists( $created, 'getObjects' ) ? $created->getObjects() : null;
            $contactId  = $contactObj && method_exists( $contactObj, 'getId' ) ? $contactObj->getId() : null;
        }

        // Adresse anhängen
        $street = trim( ( $billing->address_1 ?? $billing->address_line_1 ?? '' ) . ' ' . ( $billing->address_2 ?? '' ) );
        $addr = new ModelContactAddress( $this->filterPayload( [
            'object_name' => 'ContactAddress',
            'contact'    => [ 'id' => $contactId, 'objectName' => 'Contact' ],
            'street'     => $street,
            'zip'        => $billing->postcode ?? $billing->postal_code ?? '',
            'city'       => $billing->city ?? '',
            'country'    => $this->getCountryRef( $billing->country ?? 'DE' ),
            'name'       => $company ?: $this->resolveName( $order ),
            'category'   => [ 'id' => 1, 'objectName' => 'Category' ],
        ] ) );
        try { $contactApi->createContactAddress( $addr ); } catch ( \Throwable $e ) {}

        // Kommunikationsweg E-Mail anlegen, falls vorhanden
        $email = $billing->email ?? $order->customer_email ?? '';
        if ( $email ) {
            $this->createCommunicationWay( $contactApi, $contactId, 'EMAIL', $email, 2, true );
        }
        // Telefon/Mobil
        $phone = $billing->phone ?? $order->billing_phone ?? null;
        if ( $phone ) {
            $this->createCommunicationWay( $contactApi, $contactId, 'PHONE', $phone, 2, false );
        }
        $mobile = $billing->mobile ?? null;
        if ( $mobile ) {
            $this->createCommunicationWay( $contactApi, $contactId, 'MOBILE', $mobile, 4, false );
        }

        return $contactId;
    }

    /**
     * Suche bestehenden Kontakt per Email, gib ID zurück oder null.
     */
    protected function findContactByEmail( $email )
    {
        if ( ! $email ) {
            return null;
        }
        try {
            $api = new ContactApiFind( $this->http, $this->config );
            $res = $api->getContacts( null, null, null, 1, null, null, null, null, null, null, null, null, null, null, 'email=' . $email );
            $objects = method_exists( $res, 'getObjects' ) ? $res->getObjects() : [];
            $first = $objects[0] ?? null;
            if ( $first && method_exists( $first, 'getId' ) ) {
                return $first->getId();
            }
        } catch ( \Throwable $e ) {}
        return null;
    }

    protected function createInvoice( $order, $contactId )
    {
        $invoiceApi = new InvoiceApi( $this->http, $this->config );

        $invoice = new ModelInvoice( [
            'object_name' => 'Invoice',
            'map_all'     => true,
            'contact'     => [ 'id' => $contactId, 'objectName' => 'Contact' ],
            'invoice_date'=> date( 'd.m.Y', strtotime( $order->created_at ) ),
            // Nummer aus FluentCart übernehmen, wenn vorhanden
            'invoice_number' => $order->invoice_no ?? $order->receipt_number ?? null,
            'status'      => 100, // Entwurf
            'currency'    => $order->currency ?? 'EUR',
            'invoice_type'=> 'RE',
            'tax_rule'    => [ 'id' => 1, 'objectName' => 'TaxRule' ],
            'tax_rate'    => $order->order_items[0]->tax_rate ?? 19,
            // Zahlungsziel in Tagen (Standard 14, falls nichts anderes)
            'time_to_pay' => 14,
            // Rechnungsadresse aus Billing-Daten füllen
            'address_name'   => $this->resolveName( $order ),
            'address_street' => $order->billing_address->address_1 ?? $order->billing_address->address_line_1 ?? '',
            'address_zip'    => $order->billing_address->postcode ?? $order->billing_address->postal_code ?? '',
            'address_city'   => $order->billing_address->city ?? '',
            'address_country'=> $this->getCountryRef( $order->billing_address->country ?? 'DE' ),
            // default Ansprechpartner: erster SevUser des Accounts
            'contact_person' => new \Itsmind\Sevdesk\Model\ModelInvoiceContactPerson( [
                'id' => $this->getDefaultContactPersonId(),
                'object_name' => 'SevUser'
            ] ),
        ] );

        // Positionen vorbereiten
        $items = $order->order_items ?? [];
        $positions = [];
        $posNumber = 1;
        foreach ( $items as $item ) {
            // FluentCart speichert Preise in Cent; wir rechnen auf EUR um
            $price = isset( $item->unit_price ) ? ( (float) $item->unit_price / 100 ) : (float) ( $item->item_price ?? 0 );
            // rate ist oft 1 (= 19%); wir normieren auf reale USt
            $taxRate = isset( $item->rate ) ? ( (float) $item->rate > 1 ? (float) $item->rate : 19 ) : ( $item->tax_rate ?? 19 );
            $title  = $item->title ?? $item->post_title ?? $item->product_title ?? $item->item_title ?? 'Position';
            $positions[] = new \Itsmind\Sevdesk\Model\ModelInvoicePos( [
                'object_name' => 'InvoicePos',
                'map_all'     => true,
                'invoice'     => $invoice,
                'quantity'   => $item->quantity ?? 1,
                'price'      => $price,
                'price_gross'=> $price,
                'name'       => $title,
                'text'       => $title,
                'position_number' => $posNumber++,
                'tax_rate'   => $taxRate,
                'tax_rule'   => [ 'id' => 1, 'objectName' => 'TaxRule' ],
                'unity'      => [ 'id' => 1, 'objectName' => 'Unity' ],
            ] );
        }

        $save = new \Itsmind\Sevdesk\Model\SaveInvoice( [
            'invoice'          => $invoice,
            'invoice_pos_save' => $positions,
        ] );

        $created = $invoiceApi->createInvoiceByFactory( $save );
        $invoiceObj = method_exists( $created, 'getObjects' ) ? $created->getObjects() : null;
        $invoiceData = $invoiceObj && method_exists( $invoiceObj, 'getInvoice' ) ? $invoiceObj->getInvoice() : null;
        return $invoiceData && method_exists( $invoiceData, 'getId' ) ? $invoiceData->getId() : null;
    }

    /**
     * Hole den ersten SevUser als Standard-Kontaktperson und cache die ID.
     */
    protected function getDefaultContactPersonId()
    {
        if ( $this->defaultContactPersonId ) {
            return $this->defaultContactPersonId;
        }
        try {
            $userApi = new SevUserApi( $this->http, $this->config );
            $res = $userApi->getSevUsers( null, null, null, 1 );
            $obj = method_exists( $res, 'getObjects' ) ? $res->getObjects()[0] ?? null : null;
            if ( $obj && method_exists( $obj, 'getId' ) ) {
                $this->defaultContactPersonId = $obj->getId();
            }
        } catch ( \Throwable $e ) {}

        // Fallback: bekannte Admin-ID aus Account, falls API leer liefert
        if ( ! $this->defaultContactPersonId ) {
            $this->defaultContactPersonId = 1446019;
        }

        return $this->defaultContactPersonId;
    }

    /**
     * Ermittelt einen sinnvollen Namen aus Order/Billing.
     */
    public function resolveName( $order )
    {
        $name = trim( ($order->billing_first_name ?? '') . ' ' . ($order->billing_last_name ?? '') );
        if ( ! $name && isset( $order->billing_address->full_name ) ) {
            $name = $order->billing_address->full_name;
        }
        if ( ! $name && isset( $order->billing_address->company ) ) {
            $name = $order->billing_address->company;
        }
        if ( ! $name && isset( $order->customer_email ) ) {
            $name = $order->customer_email;
        }
        if ( ! $name ) {
            $name = 'FluentCart Kunde';
        }
        return $name;
    }

    /**
     * Öffentlich nutzbarer Helfer, um nur den Kontakt neu zu synchronisieren.
     * Erzeugt oder aktualisiert den Kontakt basierend auf der Order.
     */
    public function syncContactOnly( $order )
    {
        return $this->upsertContact( $order );
    }

    /**
     * Hole StaticCountry-Referenz per ISO-Code und cache sie.
     */
    protected function getCountryRef( $code )
    {
        $code = strtoupper( trim( (string) $code ) );
        if ( isset( $this->countryCache[ $code ] ) ) {
            return $this->countryCache[ $code ];
        }
        $ref = null;
        if ( $code ) {
            try {
                $host = rtrim( $this->config->getHost(), '/' );
                $url  = $host . '/StaticCountry?countryCode=' . urlencode( $code );
                $resp = $this->http->request( 'GET', $url, [
                    'headers' => [ 'Authorization' => $this->apiKey ],
                ] );
                $json = json_decode( (string) $resp->getBody(), true );
                if ( isset( $json['objects'][0]['id'] ) ) {
                    $ref = [
                        'id' => $json['objects'][0]['id'],
                        'objectName' => 'StaticCountry',
                    ];
                }
            } catch ( \Throwable $e ) {}
        }
        // Fallback: Deutschland (id 1) wenn nichts gefunden
        if ( ! $ref ) {
            $ref = [ 'id' => 1, 'objectName' => 'StaticCountry' ];
        }
        $this->countryCache[ $code ] = $ref;
        return $ref;
    }

    /**
     * Lege einen Kommunikationsweg an (Email, Telefon, Mobil).
     */
    protected function createCommunicationWay( ContactApi $api, $contactId, $type, $value, $keyId = 2, $main = false )
    {
        try {
            $cw = new \Itsmind\Sevdesk\Model\ModelContactCommunicationWaysInner( [
                'object_name' => 'CommunicationWay',
                'contact' => [ 'id' => $contactId, 'objectName' => 'Contact' ],
                'type'   => $type,
                'value'  => $value,
                'key'    => [ 'id' => $keyId, 'objectName' => 'CommunicationWayKey' ],
                'main'   => $main,
            ] );
            $api->createContactCommunicationWay( $cw );
        } catch ( \Throwable $e ) {}
    }

    /**
     * Entfernt leere Felder aus Payloads, damit PUT/POST nicht mit null-Werten scheitern.
     */
    protected function filterPayload( array $data )
    {
        return array_filter( $data, function( $value ) {
            return $value !== null && $value !== '';
        } );
    }
}
