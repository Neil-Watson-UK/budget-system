# Opportunity API – SalesOut Integration

This document describes how the SalesOut application uses the **Opportunity API** to pull opportunity data and check it against sales-out data (e.g. for discount validation).

---

## Overview

- **Purpose:** Draw down opportunity records so we can compare them to SalesOut data and validate discounts.
- **API base URL:**  
  `<!-- TODO: Add your API base URL here -->`

---

## How We Use It

1. **Pull opportunity data** from the API (endpoints and auth TBD).
2. **Match opportunities** to SalesOut transactions (e.g. by deal/quote ID, customer, or product).
3. **Compare pricing/discounts** between the opportunity and actual sales-out to flag discrepancies.

*(Details on endpoints, authentication, response format, and matching logic will be added as the API is developed.)*

---

## Salesforce OAuth – Callback URL

When setting up a **Connected App** in Salesforce (Setup → App Manager → New Connected App), use this as the **Callback URL** (Redirect URI):

```
https://eposaudioevents.com/budgets/salesout/salesforce_callback.php
```

- **Production:** use the URL above (HTTPS).
- **Sandbox / local:** use your actual base URL + `/salesout/salesforce_callback.php` (e.g. `https://localhost/budgets/salesout/salesforce_callback.php` if your server allows it; Salesforce requires HTTPS for production).

The callback script lives at `salesout/salesforce_callback.php`. After you implement the token exchange, it will store the tokens and redirect back into the app.

---

## Next Steps

- [ ] Add API base URL above.
- [ ] Document authentication (e.g. API key, OAuth, headers).
- [ ] Document endpoints used for opportunity data (list, by ID, filters).
- [ ] Document response shape (fields we need for discount checking).
- [ ] Describe matching rules (how we link opportunities to SalesOut rows).
- [ ] Add any rate limits or usage notes.
