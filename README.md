# MJ Order Sync v1.0.0

Modulo PrestaShop che invia gli ordini **in tempo reale** alla tua dashboard tramite webhook HTTP.

## Struttura del modulo

```
mjordersync/
├── mjordersync.php              # File principale del modulo
├── config.xml                   # Metadati modulo
├── classes/
│   ├── OrderPayloadBuilder.php  # Costruisce il payload JSON completo
│   └── WebhookSender.php       # Invia la richiesta HTTP con firma HMAC
└── README.md
```

## Installazione

1. Carica la cartella `mjordersync/` in `/modules/` sul tuo PrestaShop
2. Vai su **Moduli → Gestione moduli**, cerca "MJ Order Sync"
3. Clicca **Installa**
4. Vai su **Configura** e imposta l'URL del webhook

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

Il modulo salva gli ultimi invii nella tabella `ps_mjordersync_log` e li mostra nella pagina di configurazione. Include: ID ordine, evento, HTTP code, risposta, timestamp.

## Hook utilizzati

| Hook | Evento |
|------|--------|
| `actionValidateOrder` | Ordine creato (pagamento confermato) |
| `actionObjectOrderUpdateAfter` | Stato ordine aggiornato |

