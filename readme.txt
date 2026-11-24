=== Minox Koppeling voor WooCommerce ===
Contributors: kbdatasolutions
Tags: minox, boekhouding, facturatie, btw, woocommerce
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.3
WC requires at least: 8.0
WC tested up to: 9.4
<!-- x-release-please-start-version -->
Stable tag: 0.11.0
<!-- x-release-please-end -->
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Boekt elke WooCommerce-bestelling automatisch en correct in uw Minox-administratie, inclusief btw-classificatie en factuur. Gebouwd door KB Data Solutions.

== Description ==

Deze plugin koppelt je WooCommerce-webshop aan Minox via de KB Data Solutions Commerce Integration API:

* **Klant- en factuursynchronisatie** — zodra een order de door jou ingestelde status bereikt (bijvoorbeeld "In behandeling"), wordt de klant en de factuur automatisch naar Minox gestuurd.
* **Terugbetalingen** — een WooCommerce-terugbetaling op een al gesynchroniseerde order wordt automatisch als creditfactuur naar Minox gestuurd.
* **Opstart-wizard** — stap voor stap je koppeling instellen: inloggegevens invoeren en het juiste ordermoment kiezen.

**Btw-afhandeling:** facturen worden automatisch correct geclassificeerd — binnenlandse orders, EU-zakelijke orders met een geldig btw-nummer (ICP, 0% btw/verlegd), overige EU-orders (OSS) en orders buiten de EU (export, 0% btw) worden allemaal naar Minox gesynchroniseerd. **Bekende beperking:** Noord-Ierland wordt op basis van het landcode-veld niet te onderscheiden van de rest van het Verenigd Koninkrijk, en wordt daardoor als export behandeld terwijl het voor goederen eigenlijk nog EU-btw-regels volgt.

**PDF-facturen:** WooCommerce heeft zelf geen PDF-facturen. Als je [PDF Invoices & Packing Slips for WooCommerce](https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) hebt geïnstalleerd, stuurt deze plugin die PDF automatisch mee naar de boeking in Minox. Zonder zo'n plugin blijft de boeking geldig, alleen zonder bijgevoegd document.

Deze plugin vereist een actief KB Data Solutions-account met een Minox-koppeling. Neem contact op met KB Data Solutions om je inloggegevens te ontvangen.

== Installation ==

1. Installeer en activeer de plugin.
2. Je wordt automatisch doorgestuurd naar de opstart-wizard. Volg de stappen met de inloggegevens die je van KB Data Solutions hebt ontvangen.
3. Instellingen later aanpassen: via WooCommerce → Minox Koppeling (toont automatisch een instellingenpagina zodra de koppeling actief is, geen wizard meer). Onder WooCommerce → Instellingen → Integraties → Minox Koppeling staat alleen een knop naar die pagina.

== Frequently Asked Questions ==

= Werkt dit ook met andere boekhoudpakketten dan Minox? =

Op dit moment boekt de koppeling in Minox. Ondersteuning voor andere boekhoudpakketten (zoals Exact of Twinfield) komt in deze zelfde plugin; je hoeft dan niets opnieuw te installeren.
