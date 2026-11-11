# Datenformat von `BeaMessageImported::$data`

`$event->data` (und `BeaMessage::$data`) enthält alles, was beim Einlesen aus
der Nachricht ausgelesen wurde. Dateien im Files-Package gibt es zu diesem
Zeitpunkt noch nicht – sie entstehen erst bei der Übernahme; welche Datei
übernommen wurde, steht in `bea_message_files` (`file_id`, `accepted_at`).

Beispiel aus der synthetischen Testnachricht (`tests/Fixtures`, frei
erfundene Daten):

```json
{
    "bea_id": "100000001",
    "osci_id": "BRAK_TEST-0001",
    "subject": "Ladung zum Termin",
    "sender": {
        "safe_id": "DE.Justiz.00000000-0000-0000-0000-000000000001.0001",
        "name": "Amtsgericht Musterstadt",
        "street": "Gerichtsweg",
        "house_number": "1",
        "postal_code": "12345",
        "city": "Musterstadt",
        "country": "DE",
        "role": "egvp_justiz",
        "display": "Amtsgericht Musterstadt (12345 Musterstadt)"
    },
    "recipient": {
        "display": "Mustermann, Max (12345 Musterstadt)",
        "safe_id": "DE.BRAK.00000000-0000-0000-0000-000000000002.0002"
    },
    "aktenzeichen": "26/0001",
    "aktenzeichen_absender": "1 C 123/26",
    "sent_at": "2026-11-02T09:15:00+01:00",
    "received_at": "2026-11-02T09:16:00+01:00",
    "intermediary_at": "2026-11-02T09:16:00+01:00",
    "secure_channel": true,
    "eeb_requested": true,
    "xjustiz": {
        "present": true,
        "message_type": "nachricht.gds.uebermittlungSchriftgutobjekte.0005005",
        "version": "3.6.2",
        "created_at": "2026-11-02T09:15:00",
        "sender": {
            "safe_id": "DE.Justiz.00000000-0000-0000-0000-000000000001.0001",
            "aktenzeichen": "1 C 123/26",
            "message_id": "11111111-2222-3333-4444-555555555555"
        },
        "recipient": {
            "safe_id": "DE.BRAK.00000000-0000-0000-0000-000000000002.0002",
            "aktenzeichen": "26/0001"
        },
        "reference_message_id": null,
        "eeb_requested": true,
        "producer": {
            "product": "Testprodukt",
            "vendor": "Test",
            "version": "1.0"
        },
        "instances": [
            {
                "number": "1",
                "court": "X0000",
                "subject_area": "010",
                "aktenzeichen": "1 C 123/26",
                "subject_matter": "Forderung"
            }
        ],
        "parties": [
            {
                "number": "1",
                "roles": [
                    "027"
                ],
                "name": "Erika Musterfrau"
            },
            {
                "number": "2",
                "roles": [
                    "028"
                ],
                "name": "Beispiel GmbH"
            }
        ],
        "documents": [
            {
                "id": "doc-1",
                "number": "1",
                "document_class": "017",
                "display_name": "Ladung zum Termin",
                "files": [
                    {
                        "name": "Ladung.pdf",
                        "part": "001"
                    },
                    {
                        "name": "Ladung.pdf.p7s",
                        "part": "003"
                    }
                ]
            },
            {
                "id": "doc-2",
                "number": "2",
                "document_class": "015",
                "display_name": "Klageerwiderung der Gegenseite",
                "files": [
                    {
                        "name": "Klageerwiderung.pdf",
                        "part": "001"
                    }
                ]
            }
        ]
    },
    "vhn": {
        "present": true,
        "version": "2.0",
        "sender": {
            "safe_id": "DE.Justiz.00000000-0000-0000-0000-000000000001.0001",
            "name": "Amtsgericht Musterstadt",
            "street": "Gerichtsweg",
            "house_number": "1",
            "postal_code": "12345",
            "city": "Musterstadt",
            "country": "DE"
        },
        "role": "egvp_justiz",
        "secure_channel": true,
        "hashes": [
            {
                "algorithm": "SHA256",
                "value": "GgFFWglR3RLPSa6FvWgy0q1+kKUkrhCO3hbZKNEmyRs="
            },
            {
                "algorithm": "SHA256",
                "value": "mxNbp8/s3x4O6m4D3y18q1Dk1gtkIehsLFGy4xJS95A="
            },
            {
                "algorithm": "SHA256",
                "value": "Umh2Em9hm48TzKxbCk7ggvHw2G/GpONhXVjALk2ub+Y="
            }
        ],
        "producer": {
            "product": "Testprodukt 1.0"
        }
    },
    "attachments": [
        {
            "name": "Klageerwiderung.pdf",
            "path": "Nachricht_100000001_Testnachricht/Klageerwiderung.pdf",
            "size": 269,
            "sha256": "9b135ba7cfecdf1e0eea6e03df2d7cab50e4d60b6421e86c2c51b2e31252f790",
            "display_name": "Klageerwiderung der Gegenseite",
            "document_class": "015",
            "document_id": "doc-2",
            "vhn_covered": true
        },
        {
            "name": "Ladung.pdf",
            "path": "Nachricht_100000001_Testnachricht/Ladung.pdf",
            "size": 260,
            "sha256": "1a01455a0951dd12cf49ae85bd6832d2ad7e90a524ae108ede16d928d126c91b",
            "display_name": "Ladung zum Termin",
            "document_class": "017",
            "document_id": "doc-1",
            "vhn_covered": true
        },
        {
            "name": "Ladung.pdf.p7s",
            "path": "Nachricht_100000001_Testnachricht/Ladung.pdf.p7s",
            "size": 1520,
            "sha256": "eca99aa649ebb1dcfcdce9734f4b618a2d2e148ee2e7752d8491846c5221637b",
            "display_name": "Ladung zum Termin",
            "document_class": "017",
            "document_id": "doc-1",
            "signature_of": "Ladung.pdf",
            "vhn_covered": false
        }
    ],
    "meta_files": {
        "osci": "Nachricht_100000001_Testnachricht/100000001.xml",
        "export": "Nachricht_100000001_Testnachricht/100000001_export.html",
        "business_card": null,
        "verification_report": null,
        "vhn": "Nachricht_100000001_Testnachricht/vhn.xml",
        "vhn_signature": "Nachricht_100000001_Testnachricht/vhn.xml.p7s",
        "technical_report": null,
        "xjustiz": "Nachricht_100000001_Testnachricht/xjustiz_nachricht.xml"
    },
    "export_fields": {
        "Absender": "Amtsgericht Musterstadt (12345 Musterstadt)",
        "Empfänger": "Mustermann, Max (12345 Musterstadt)",
        "Betreff": "Ladung zum Termin",
        "OSCI-Nachrichten-ID": "BRAK_TEST-0001",
        "Aktenzeichen Sender": "1 C 123/26",
        "Aktenzeichen Empfänger": "26/0001",
        "Gesendet": "02.11.2026 09:15",
        "Eingang Postfach": "02.11.2026 09:16",
        "Eingang Intermediär": "02.11.2026 09:16"
    },
    "journal": [
        {
            "Ereignis": "Zeitpunkt des Empfangs (bei empfangenen Nachrichten)",
            "Zeitpunkt": "02.11.2026 09:16"
        }
    ],
    "integrity": {
        "vhn_hashes": 3,
        "matched": [
            "Ladung.pdf",
            "Klageerwiderung.pdf",
            "xjustiz_nachricht.xml"
        ],
        "unmatched_hashes": 0,
        "uncovered": [],
        "complete": true
    },
    "warnings": [],
    "vhn_signature": {
        "status": "signature_ok",
        "signer": "VHN Test",
        "issuer": "VHN Test",
        "signed_at": "2026-11-02T08:15:00+00:00"
    }
}
```

## Felder

| Schlüssel | Quelle | Inhalt |
|---|---|---|
| `bea_id` | Exportansicht / Dateiname | beA-Nachrichten-ID |
| `osci_id` | Exportansicht / OSCI-Umschlag | OSCI-Nachrichten-ID |
| `aktenzeichen` | XJustiz (Empfänger), sonst Exportansicht | eigenes Aktenzeichen – Freitext des Absenders |
| `aktenzeichen_absender` | XJustiz (Absender), sonst Exportansicht | Aktenzeichen des Absenders |
| `sender` | VHN, XJustiz, Exportansicht | Name, Anschrift, SAFE-ID, Rolle (`egvp_justiz`, `egvp_bebpo`, …) |
| `recipient` | Exportansicht, XJustiz | Anzeige und SAFE-ID |
| `sent_at`, `received_at`, `intermediary_at` | Exportansicht | Gesendet, Eingang Postfach, Eingang Intermediär (ISO 8601, Ortszeit) |
| `secure_channel` | VHN | sicherer Übermittlungsweg |
| `eeb_requested` | XJustiz | elektronisches Empfangsbekenntnis angefordert |
| `xjustiz.instances` | XJustiz | Instanz, Gericht (Code), Sachgebiet (Code), Aktenzeichen, Gegenstand |
| `xjustiz.parties` | XJustiz | Beteiligte mit Rollen-Codes |
| `xjustiz.documents` | XJustiz | Dokumente mit Dokumentklasse (Code), Anzeigename, Dateien |
| `attachments` | Dateien | Name, Pfad im ZIP, Größe, SHA-256, Anzeigename, Dokumentklasse, `vhn_covered`, `signature_of` |
| `integrity` | VHN ↔ Dateien | `complete`, zugeordnete Dateien, fehlende Prüfsummen, nicht erfasste Dateien |
| `vhn_signature` | `vhn.xml.p7s` | Status, Signer, Aussteller, Signaturzeit |
| `export_fields` | Exportansicht | alle beschrifteten Felder im Wortlaut |
| `journal` | Exportansicht | Nachrichtenjournal |
| `warnings` | – | Hinweise, z. B. fehlender VHN oder abweichende Prüfsummen |

Die Codes (Gericht, Sachgebiet, Dokumentklasse, Rollen) stammen aus den
XJustiz-Codelisten und werden unverändert übernommen.
