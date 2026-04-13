# ADR-001: Cross-Currency Transfers Are Out of Scope

**Status:** Accepted  
**Date:** 2026-04-13  
**Decision Makers:** Engineering Team

## Context

The fund-transfer API requires all three currencies to agree for a transfer:
the source account currency, the destination account currency, and the explicit
currency stated in the transfer request. This is enforced by `CurrencyMismatchRule`.

Integrators and investors will ask about cross-currency (FX) support on day one.
This ADR documents the deliberate decision and provides a migration path.

## Decision

**Cross-currency transfers are explicitly not supported.**

All transfers must be same-currency. `CurrencyMismatchRule` throws
`CurrencyMismatchException` (HTTP 422) when any currency disagrees.

## Rationale

1. **Regulatory complexity:** FX transactions require adherence to different
   regulations per jurisdiction (MiFID II in EU, Dodd-Frank in US). Adding FX
   without a compliance framework creates legal risk.

2. **Rate feed dependency:** Real-time FX requires an external rate provider
   (Reuters, Bloomberg, ECB reference rates). Introducing an external dependency
   into the critical transfer path adds latency, failure modes, and cost.

3. **Two-leg accounting:** An FX transfer is not a simple debit/credit — it
   creates a two-leg transaction (debit in source currency, credit in destination
   currency at an agreed rate), plus a potential FX margin entry. The current
   ledger model assumes a single currency per transaction.

4. **Settlement risk:** Cross-currency involves settlement timing mismatches
   (T+0 vs T+2). The current synchronous commit model does not account for
   deferred settlement.

5. **MVP focus:** Same-currency transfers cover 95%+ of domestic use cases
   (SEPA EUR, ACH USD, UPI INR). FX can be added incrementally without
   rearchitecting the core pipeline.

## Consequences

- Transfers between accounts of different currencies return HTTP 422.
- API consumers must pre-convert funds externally before transferring.
- The `currency` field in the transfer request acts as a safety check — clients
  must explicitly state the expected currency.

## Migration Path (When FX Is Needed)

1. Define `FxRateProviderInterface` with implementations for ECB, Reuters, etc.
2. Create `FxConversionService` that locks a rate, calculates the converted
   amount, and records the applied rate + margin.
3. Replace `CurrencyMismatchRule` with `FxConversionRule` that calls the FX
   service when currencies differ and passes through when they agree.
4. Extend `Transaction` with `fx_rate`, `source_currency`, `destination_currency`
   columns.
5. Extend `LedgerEntryFactory` to produce two-currency entry pairs.
6. Add rate expiry / requote logic for PSD2 / MiFID II compliance.

## Related

- `src/Service/Transfer/Rule/CurrencyMismatchRule.php` — enforcement point
- `src/Entity/LedgerEntry.php` — single-currency ledger model
