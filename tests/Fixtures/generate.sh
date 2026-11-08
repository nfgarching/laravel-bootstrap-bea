#!/usr/bin/env bash
# Erzeugt die synthetische beA-Testnachricht (frei erfundene Daten).
# Aufruf: bash tests/Fixtures/generate.sh
set -euo pipefail
cd "$(dirname "$0")"
M=Nachricht_100000001_Testnachricht
rm -rf "$M" && mkdir -p "$M"
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT

# Testschlüssel (wird nicht aufbewahrt)
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -keyout "$work/key.pem" -out "$work/cert.pem" \
  -subj "/CN=VHN Test/O=Testbehoerde/C=DE" 2>/dev/null

# Anhänge: zwei minimale PDFs
mkpdf() {
  printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n4 0 obj<</Title(%s)/Producer(Testgenerator)>>endobj\ntrailer<</Root 1 0 R/Info 4 0 R>>\n%%%%EOF\n' "$1" > "$2"
}
mkpdf "Ladung" "$M/Ladung.pdf"
mkpdf "Klageerwiderung" "$M/Klageerwiderung.pdf"
openssl cms -sign -binary -outform DER -in "$M/Ladung.pdf" -signer "$work/cert.pem" -inkey "$work/key.pem" -out "$M/Ladung.pdf.p7s"

cat > "$M/xjustiz_nachricht.xml" <<'XML'
<tns:nachricht.gds.uebermittlungSchriftgutobjekte.0005005 xmlns:tns="http://www.xjustiz.de">
   <tns:nachrichtenkopf xjustizVersion="3.6.2">
      <tns:erstellungszeitpunkt>2026-11-02T09:15:00</tns:erstellungszeitpunkt>
      <tns:absender>
         <tns:informationen><tns:auswahl_kommunikationspartner><tns:sonstige>DE.Justiz.00000000-0000-0000-0000-000000000001.0001</tns:sonstige></tns:auswahl_kommunikationspartner></tns:informationen>
         <tns:aktenzeichen>1 C 123/26</tns:aktenzeichen>
         <tns:eigeneNachrichtenID>11111111-2222-3333-4444-555555555555</tns:eigeneNachrichtenID>
      </tns:absender>
      <tns:empfaenger>
         <tns:informationen><tns:auswahl_kommunikationspartner><tns:sonstige>DE.BRAK.00000000-0000-0000-0000-000000000002.0002</tns:sonstige></tns:auswahl_kommunikationspartner></tns:informationen>
         <tns:auswahl_aktenzeichen><tns:aktenzeichen.freitext>26/0001</tns:aktenzeichen.freitext></tns:auswahl_aktenzeichen>
      </tns:empfaenger>
      <tns:ruecksendungEEB.erforderlich>true</tns:ruecksendungEEB.erforderlich>
      <tns:herstellerinformation><tns:nameDesProdukts>Testprodukt</tns:nameDesProdukts><tns:herstellerDesProdukts>Test</tns:herstellerDesProdukts><tns:version>1.0</tns:version></tns:herstellerinformation>
   </tns:nachrichtenkopf>
   <tns:grunddaten>
      <tns:verfahrensdaten>
         <tns:instanzdaten>
            <tns:instanznummer>1</tns:instanznummer>
            <tns:sachgebiet><code>010</code></tns:sachgebiet>
            <tns:auswahl_instanzbehoerde><tns:gericht><code>X0000</code></tns:gericht></tns:auswahl_instanzbehoerde>
            <tns:aktenzeichen><tns:aktenzeichen.freitext>1 C 123/26</tns:aktenzeichen.freitext></tns:aktenzeichen>
            <tns:verfahrensgegenstand><tns:gegenstand>Forderung</tns:gegenstand></tns:verfahrensgegenstand>
         </tns:instanzdaten>
         <tns:beteiligung>
            <tns:beteiligtennummer>1</tns:beteiligtennummer>
            <tns:rolle><tns:rollenbezeichnung><code>027</code></tns:rollenbezeichnung></tns:rolle>
            <tns:beteiligter><tns:auswahl_beteiligter><tns:natuerlichePerson><tns:vollerName><tns:vorname>Erika</tns:vorname><tns:nachname>Musterfrau</tns:nachname></tns:vollerName></tns:natuerlichePerson></tns:auswahl_beteiligter></tns:beteiligter>
         </tns:beteiligung>
         <tns:beteiligung>
            <tns:beteiligtennummer>2</tns:beteiligtennummer>
            <tns:rolle><tns:rollenbezeichnung><code>028</code></tns:rollenbezeichnung></tns:rolle>
            <tns:beteiligter><tns:auswahl_beteiligter><tns:organisation><tns:bezeichnung><tns:bezeichnung.aktuell>Beispiel GmbH</tns:bezeichnung.aktuell></tns:bezeichnung></tns:organisation></tns:auswahl_beteiligter></tns:beteiligter>
         </tns:beteiligung>
      </tns:verfahrensdaten>
   </tns:grunddaten>
   <tns:schriftgutobjekte>
      <tns:dokument>
         <tns:identifikation><tns:id>doc-1</tns:id><tns:nummerImUebergeordnetenContainer>1</tns:nummerImUebergeordnetenContainer></tns:identifikation>
         <tns:xjustiz.fachspezifischeDaten>
            <tns:dokumentklasse><code>017</code></tns:dokumentklasse>
            <tns:anzeigename>Ladung zum Termin</tns:anzeigename>
            <tns:datei><tns:dateiname>Ladung.pdf</tns:dateiname><tns:bestandteil><code>001</code></tns:bestandteil></tns:datei>
            <tns:datei><tns:dateiname>Ladung.pdf.p7s</tns:dateiname><tns:bestandteil><code>003</code></tns:bestandteil></tns:datei>
         </tns:xjustiz.fachspezifischeDaten>
      </tns:dokument>
      <tns:dokument>
         <tns:identifikation><tns:id>doc-2</tns:id><tns:nummerImUebergeordnetenContainer>2</tns:nummerImUebergeordnetenContainer></tns:identifikation>
         <tns:xjustiz.fachspezifischeDaten>
            <tns:dokumentklasse><code>015</code></tns:dokumentklasse>
            <tns:anzeigename>Klageerwiderung der Gegenseite</tns:anzeigename>
            <tns:datei><tns:dateiname>Klageerwiderung.pdf</tns:dateiname><tns:bestandteil><code>001</code></tns:bestandteil></tns:datei>
         </tns:xjustiz.fachspezifischeDaten>
      </tns:dokument>
   </tns:schriftgutobjekte>
</tns:nachricht.gds.uebermittlungSchriftgutobjekte.0005005>
XML

h() { openssl dgst -sha256 -binary "$1" | base64; }
cat > "$M/vhn.xml" <<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<VHN Version="2.0">
    <Fachlich>
        <Absender>
            <Nutzer_ID>DE.Justiz.00000000-0000-0000-0000-000000000001.0001</Nutzer_ID>
            <Name>Amtsgericht Musterstadt</Name>
            <Strasse>Gerichtsweg</Strasse>
            <Hausnummer>1</Hausnummer>
            <Postleitzahl>12345</Postleitzahl>
            <Ort>Musterstadt</Ort>
            <Land>DE</Land>
        </Absender>
        <EGVP_Rollenwerte>egvp_justiz</EGVP_Rollenwerte>
        <Sicherer_Uebermittlungsweg>ja</Sicherer_Uebermittlungsweg>
        <Dokument Algorithm="SHA256"><Hashwert>$(h "$M/Ladung.pdf")</Hashwert></Dokument>
        <Dokument Algorithm="SHA256"><Hashwert>$(h "$M/Klageerwiderung.pdf")</Hashwert></Dokument>
        <Dokument Algorithm="SHA256"><Hashwert>$(h "$M/xjustiz_nachricht.xml")</Hashwert></Dokument>
    </Fachlich>
    <Technisch>
        <HerstellerInformation><Name_des_Produkts_und_Softwareversion>Testprodukt 1.0</Name_des_Produkts_und_Softwareversion></HerstellerInformation>
    </Technisch>
</VHN>
XML
openssl cms -sign -binary -outform DER -in "$M/vhn.xml" -signer "$work/cert.pem" -inkey "$work/key.pem" -out "$M/vhn.xml.p7s"

printf 'MIME-Version: 1.0\n\n<osci:MessageId>%s</osci:MessageId>\n<osci:Subject>Ladung zum Termin</osci:Subject>\n' \
  "$(printf 'BRAK_TEST-0001' | base64)" > "$M/100000001.xml"

cat > "$M/100000001_export.html" <<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Export der Nachricht mit Id 100000001</title></head>
<body><div id="BEAContainer">
<div class="form-group row"><label for="messageSender" class="col-form-label">Absender:</label><div><span id="messageSender">Amtsgericht Musterstadt (12345 Musterstadt)</span></div></div>
<div class="form-group row"><label class="col-form-label">Empfänger:</label><div><span>Mustermann, Max (12345 Musterstadt)</span></div></div>
<div class="form-group row"><label for="messageSubjectPrint" class="col-form-label">Betreff:</label><div><span id="messageSubjectPrint">Ladung zum Termin</span></div></div>
<div class="form-group row"><label for="osciId" class="col-form-label">OSCI-Nachrichten-ID:</label><div><span id="osciId">BRAK_TEST-0001</span></div></div>
<div class="form-group row"><label class="col-form-label">Aktenzeichen Sender:</label><div><span>1 C 123/26</span></div><label class="control-label">Aktenzeichen Empfänger:</label><div><span>26/0001</span></div></div>
<div class="form-group row"><label class="col-form-label">Gesendet:</label><div><span>02.11.2026 09:15</span></div>
<label class="col-form-label">Eingang Postfach:</label><div><span>02.11.2026 09:16</span></div>
<label class="col-form-label">Eingang Intermediär:</label><div><span>02.11.2026 09:16</span></div></div>
<div id="panelAttachmentTable"><div id="attachmentTable"><table><thead><tr><th></th><th>Dateiname</th><th>Größe</th></tr></thead>
<tbody><tr><td></td><td>Ladung.pdf</td><td>1 KB</td></tr><tr><td></td><td>Klageerwiderung.pdf</td><td>1 KB</td></tr></tbody></table></div></div>
<div id="messageJournalTable"><table><thead><tr><th>Ereignis</th><th>Zeitpunkt</th></tr></thead>
<tbody><tr><td>Zeitpunkt des Empfangs (bei empfangenen Nachrichten)</td><td>02.11.2026 09:16</td></tr></tbody></table></div>
</div></body></html>
HTML
echo "Testnachricht erzeugt: $M"
