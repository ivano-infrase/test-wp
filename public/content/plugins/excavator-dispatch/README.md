# Excavator Dispatch

WordPress plugin per inviare comunicazioni WhatsApp su disponibilità di escavatori, leggendo l'elenco da un foglio Excel su Microsoft 365 e i destinatari da Google Contacts.

## Architettura

- **PHP 7.4+**, autoload PSR-4 (`InfraSe\ExcavatorDispatch\`).
- Backend: WP REST API (`/wp-json/excavator-dispatch/v1/{machines,recipients,templates,dispatch}`).
- Frontend admin: PHP + JS vanilla (nessun build step).
- Storage: `wp_options` (credenziali crittografate con `sodium_crypto_secretbox`) + tabella custom `wp_excdis_dispatches` per lo storico invii.

## Setup

### 1. Installazione dipendenze (in `plugins/excavator-dispatch/`)

```sh
composer install --no-dev -o
```

Dipendenze runtime principali:
- `microsoft/microsoft-graph` (Graph SDK, opzionale: il plugin chiama Graph direttamente via `wp_remote_*` per ridurre footprint)
- `google/apiclient`
- `guzzlehttp/guzzle`

### 2. Chiave di crittografia

Aggiungere in `wp-config.php` (almeno 32 caratteri):

```php
define('EXCDIS_ENCRYPTION_KEY', 'una-chiave-lunga-e-segreta-almeno-32-char');
```

Se omessa, il plugin deriva una chiave da `AUTH_KEY` (sconsigliato per produzione).

### 3. Microsoft 365 (Graph API)

1. Azure Portal → App registrations → Nuova registrazione.
2. API permissions → Microsoft Graph → Application permissions: `Files.Read.All` (o `Sites.Read.All`) — grant admin consent.
3. Generare Client Secret.
4. Annotare Tenant ID, Client ID, Client Secret.
5. Recuperare Drive ID e Item ID/Path del file Excel (Graph Explorer: `GET /drives/{driveId}/root/children`).
6. Consigliato: definire una **Tabella Excel** (es. `Macchine`) sul foglio — il plugin la userà per intestazioni stabili.

### 4. Google Contacts (People API)

1. Google Cloud Console → progetto → abilitare People API.
2. Creare un **Service Account**, generare chiave JSON.
3. Abilitare **domain-wide delegation** sul service account.
4. Workspace Admin → Security → API controls → Domain-wide delegation: aggiungere il Client ID del SA con scope `https://www.googleapis.com/auth/contacts.readonly`.
5. Nella pagina Impostazioni: incollare il JSON e impostare l'email Workspace di cui il SA impersonerà i contatti.
6. Opzionale: limitare ai gruppi indicando i `contactGroups/{id}` (uno per riga).

### 5. WhatsApp Cloud API

1. Meta for Developers → app Business → prodotto WhatsApp.
2. Annotare WhatsApp Business Account ID, Phone Number ID, Access Token (consigliato system user long-lived).
3. Approvare almeno un template (categoria UTILITY o MARKETING) nella lingua di lavoro (es. `it`).

### 6. Mapping template

Nella pagina Impostazioni → "Mapping template (JSON)":

```json
{
  "avviso_macchine_disponibili": {
    "body": [
      "{count}",
      "{list:Modello,Anno,Stato}"
    ]
  }
}
```

Espressioni supportate nel mapping:
- `{col:Colonna}` — valore della colonna dalla prima macchina selezionata
- `{list:Col1,Col2}` — elenco puntato che concatena le colonne per ogni macchina selezionata
- `{count}` — numero di macchine selezionate
- testo libero — passato così com'è

Le variabili di template Meta (`{{1}}`, `{{2}}`, …) sono popolate nell'ordine dell'array.

## Uso

Menu WP → **Excavator Dispatch**:
1. La pagina mostra a sinistra le macchine filtrate dall'Excel, a destra i destinatari da Google Contacts.
2. Selezionare manualmente macchine e destinatari (checkbox).
3. Scegliere il template, verificare l'anteprima.
4. Cliccare "Invia messaggio WhatsApp".
5. L'esito di ciascun invio viene salvato in **Storico invii**.

## Note

- Cache transient (5 min) su Excel e Contacts; usare "Aggiorna" per forzare il refresh.
- Throttle soft di ~20 messaggi/secondo. Per volumi alti spostare l'invio su un job in coda (fase 2).
- Webhook delivery/read receipts non implementati nell'MVP.
