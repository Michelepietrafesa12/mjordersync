# MJ Order Sync v1.1.0

Modulo PrestaShop che invia gli ordini alla tua dashboard tramite webhook HTTP **asincrono** (coda + cron). Gli hook di PrestaShop accodano solo l'evento (insert DB in pochi millisecondi): la consegna HTTP avviene fuori dalla transazione di checkout, quindi il cliente non aspetta mai il webhook e non c'è rischio di timeout PSP / doppi addebiti.

## Architettura

```
┌────────────────────┐    insert     ┌──────────────────────┐    HTTP POST   ┌──────────┐
│ hookActionValidate │──────────────▶│ ps_mjordersync_queue │───────────────▶│  n8n /   │
│ Order (sync, ms)   │               │ (pending / retry)    │  via cron      │ Supabase │
└────────────────────┘               └──────────────────────┘                └──────────┘
                                              ▲
                                              │ retry backoff (1m → 32m, max 6 tentativi)
                                              │
                                       ┌──────┴──────┐
                                       │  CRON       │
                                       │  cron.php   │
                                       └─────────────┘
```

## Struttura del modulo

```
mjordersync/
├── mjordersync.php                  # File principale + hook (enqueue only)
├── config.xml                       # Metadati modulo
├── classes/
│   ├── OrderPayloadBuilder.php      # Costruisce il payload JSON completo
│   ├── WebhookSender.php            # Invia la richiesta HTTP con firma HMAC
│   └── QueueProcessor.php           # Consumer della coda (chiamato dal cron)
├── controllers/front/
│   └── cron.php                     # Endpoint cron protetto da token
└── README.md
```

## Installazione

1. Carica la cartella `mjordersync/` in `/modules/` sul tuo PrestaShop
2. Vai su **Moduli → Gestione moduli**, cerca "MJ Order Sync"
3. Clicca **Installa**
4. Vai su **Configura**, imposta l'URL del webhook e copia l'URL del cron
5. Configura il cron (vedi sezione **Cron**)

## Cron

L'invio del webhook è asincrono: l'hook di checkout fa solo un `INSERT` in `ps_mjordersync_queue`. Devi configurare un cron che chiama l'endpoint del modulo per processare la coda.

Nella pagina **Configura** del modulo trovi l'URL pronto all'uso, ad esempio:

```
https://tuo-shop.it/index.php?fc=module&module=mjordersync&controller=cron&token=<CRON_TOKEN>
```

### Opzione A — crontab di sistema

```cron
* * * * * curl -fsS --max-time 60 "https://tuo-shop.it/index.php?fc=module&module=mjordersync&controller=cron&token=XXX" > /dev/null
```

### Opzione B — modulo PrestaShop *Cron Tasks Manager* (`cronjobs`)

Aggiungi una task che richiama lo stesso URL ogni minuto (o ogni 5 minuti per shop a basso traffico).

### Parametri opzionali

| Parametro | Default | Note |
|-----------|---------|------|
| `batch` | `25` | Numero massimo di eventi processati per esecuzione (1–200). |

La risposta è JSON, es. `{"ok":true,"stats":{"processed":3,"success":3,"failed":0,"retry":0},"ts":"..."}`.

### Retry / backoff

Ogni evento ha fino a **6 tentativi** con backoff esponenziale: **1m, 2m, 4m, 8m, 16m, 32m**. Dopo l'ultimo fallimento la riga passa a `status = failed` e non viene più ritentata automaticamente (il `last_error` resta visibile per il debug).

## Configurazione

| Campo | Descrizione |
|-------|-------------|
| **URL Webhook** | Es. `https://tuo-n8n.dominio.it/webhook/ordini` |
| **Secret Key** | Stringa segreta per la firma HMAC-SHA256 (opzionale) |
| **Invia su nuovo ordine** | Attiva/disattiva invio su `actionValidateOrder` |
| **Invia su aggiornamento stato** | Attiva/disattiva invio su `actionObjectOrderUpdateAfter` |

## Payload JSON inviato

```json
{
  "event": "order.created",
  "timestamp": "2025-03-01T10:30:00+01:00",
  "shop_url": "https://compralosubito24.it",
  "order": {
    "id": 1234,
    "reference": "BCZXK-XVNYD",
    "date_add": "2025-03-01 10:30:00",
    "date_upd": "2025-03-01 10:30:05"
  },
  "customer": {
    "id": 567,
    "firstname": "Mario",
    "lastname": "Rossi",
    "email": "mario@email.it",
    "orders_count": 3
  },
  "addresses": {
    "delivery": {
      "firstname": "Mario", "lastname": "Rossi",
      "address1": "Via Roma 1", "postcode": "71016",
      "city": "San Severo", "country": "IT",
      "phone": "3331234567"
    },
    "invoice": { "...": "..." }
  },
  "products": [
    {
      "id_product": 89,
      "reference": "CRE-PROT-CHOC",
      "name": "Creatina Monoidrata 500g - Cioccolato",
      "quantity": 2,
      "price_unit_tax_incl": 24.90,
      "price_total_tax_incl": 49.80,
      "tax_rate": 22.0
    }
  ],
  "totals": {
    "total_paid_tax_incl": 54.70,
    "total_products_wt": 49.80,
    "total_shipping_tax_incl": 4.90,
    "currency_iso": "EUR"
  },
  "payment": {
    "method": "Carta di credito",
    "module": "ps_checkout"
  },
  "status": {
    "id": 2,
    "name": "Pagamento accettato",
    "paid": true,
    "shipped": false
  },
  "carrier": {
    "id": 3,
    "name": "GLS",
    "tracking_number": "",
    "weight": 1.05
  }
}
```

## Headers HTTP inviati

```
Content-Type: application/json
User-Agent: MjOrderSync/1.0 PrestaShop/8.2.3
X-MJSync-Event: order.created
X-MJSync-Timestamp: 1740823800
X-MJSync-Signature: sha256=<HMAC-SHA256 del body JSON>
```

## Verifica firma in n8n

Nel tuo workflow n8n, aggiungi un nodo **Code** dopo il webhook:

```javascript
// Verifica firma HMAC-SHA256
const crypto = require('crypto');

const secretKey = 'la-tua-secret-key';
const receivedSig = $input.first().headers['x-mjsync-signature'];
const body = JSON.stringify($input.first().body);

const expectedSig = 'sha256=' + crypto
  .createHmac('sha256', secretKey)
  .update(body)
  .digest('hex');

if (receivedSig !== expectedSig) {
  throw new Error('Firma non valida - richiesta rifiutata');
}

return $input.all();
```

## Verifica firma in Supabase Edge Function

```typescript
import { createHmac } from "https://deno.land/std/crypto/mod.ts";

const secret = Deno.env.get("MJSYNC_SECRET_KEY")!;
const signature = req.headers.get("x-mjsync-signature") ?? "";
const body = await req.text();

const expected = "sha256=" + createHmac("sha256", secret)
  .update(new TextEncoder().encode(body))
  .toString("hex");

if (signature !== expected) {
  return new Response("Unauthorized", { status: 401 });
}
```

## Log invii

Il modulo salva gli ultimi invii nella tabella `ps_mjordersync_log` (popolata dal **QueueProcessor**, non dagli hook) e li mostra nella pagina di configurazione. Include: ID ordine, evento, HTTP code, risposta, timestamp.

## Tabelle DB

| Tabella | Scopo |
|---------|-------|
| `ps_mjordersync_queue` | Eventi in attesa di consegna (con `payload_snapshot`, `retries`, `next_retry_at`, `status`). |
| `ps_mjordersync_log` | Storico tentativi di invio HTTP (popolato dal cron). |

## Hook utilizzati

| Hook | Evento | Comportamento |
|------|--------|---------------|
| `actionValidateOrder` | Ordine creato (pagamento confermato) | **Enqueue only** — `INSERT` in `ps_mjordersync_queue`. Nessuna cURL nel checkout. |
| `actionObjectOrderUpdateAfter` | Stato ordine aggiornato | **Enqueue only** — stesso meccanismo. |

> Storico: nella v1.0 questi hook eseguivano una cURL sincrona con timeout fino a 13s, che poteva bloccare la pagina di pagamento e causare timeout dal PSP / doppi addebiti su carta di credito. La v1.1 sposta la chiamata HTTP nel cron.

