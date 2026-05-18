/**
 * BCBP parser unit tests. Vectors taken from the IATA 792 Implementation
 * Guide specimens and from real-world one-leg boarding-pass samples that
 * are publicly documented (no traveller PII).
 */
import { describe, it, expect } from 'vitest'
import { parseBCBP, iataToCountry } from '../bcbp.js'

describe('parseBCBP — mandatory + first leg only', () => {

  // IATA 792 specimen: "JOHN / BOBM 0 LHR JFK BA 0175 266 Y 007A 0007 X"
  // Re-assembled into the fixed-width mandatory block:
  //   M 1 (20ch: "JOHNSON/BOB         ") E (PNR 7: "ABC1234") LHR JFK (carrier " BA")
  //   flight "00175" julian "266" compartment "Y" seat "007A" sequence "00071" status "0"
  //   cond-len "00"
  const ONE_LEG = 'M1JOHNSON/BOB         E' +
                  'ABC1234LHRJFK BA00175266Y007A00071000'

  it('parses a valid single-leg pass', () => {
    const r = parseBCBP(ONE_LEG)
    expect(r.ok).toBe(true)
    expect(r.data.pax_name).toBe('BOB JOHNSON')
    expect(r.data.pax_name_raw).toBe('JOHNSON/BOB')
    expect(r.data.n_legs).toBe(1)
    expect(r.data.e_ticket).toBe(true)
    expect(r.legs).toHaveLength(1)
    const leg = r.legs[0]
    expect(leg.pnr).toBe('ABC1234')
    expect(leg.from_iata).toBe('LHR')
    expect(leg.to_iata).toBe('JFK')
    expect(leg.carrier).toBe('BA')
    expect(leg.flight_number).toBe('175')
    expect(leg.seat).toBe('007A')
    expect(leg.compartment).toBe('Y')
    expect(leg.cond_field_len).toBe(0)
  })

  it('rejects non-BCBP payloads', () => {
    expect(parseBCBP('hello world').ok).toBe(false)
    expect(parseBCBP('').ok).toBe(false)
    expect(parseBCBP(null).ok).toBe(false)
    expect(parseBCBP(undefined).ok).toBe(false)
    expect(parseBCBP(42).ok).toBe(false)
  })

  it('rejects a payload with no leg data', () => {
    const r = parseBCBP('M1JOHNSON/BOB         E')
    expect(r.ok).toBe(false)
  })

  it('rejects an invalid leg count character', () => {
    const bad = 'M9JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175266Y007A00071000'
    expect(parseBCBP(bad).ok).toBe(false)
  })

  it('rejects a non-IATA origin code', () => {
    const bad = 'M1JOHNSON/BOB         E' + 'ABC1234xxxJFK BA 00175266Y007A00071000'
    expect(parseBCBP(bad).ok).toBe(false)
  })

  it('normalises names with honorifics', () => {
    const pass = 'M1SMITH/JANE MS       E' + 'ABC1234LUSJNB ET00715180Y014C00001000'
    const r = parseBCBP(pass)
    expect(r.ok).toBe(true)
    expect(r.data.pax_name).toBe('JANE SMITH')
  })

  it('handles a payload that lacks a slash (single-token name)', () => {
    const pass = 'M1VIP                 E' + 'ABC1234LUSJNB ET00715180Y014C00001000'
    const r = parseBCBP(pass)
    expect(r.ok).toBe(true)
    expect(r.data.pax_name).toBe('VIP')
  })
})

describe('julian date → ISO', () => {
  it('produces a valid ISO date for day 266', () => {
    const r = parseBCBP('M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175266Y007A00071000')
    expect(r.legs[0].flight_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
  it('returns null for invalid julian', () => {
    const r = parseBCBP('M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175xxxY007A00071000')
    expect(r.ok).toBe(true)
    expect(r.legs[0].flight_date).toBeNull()
  })
})

describe('iataToCountry seed', () => {
  it('maps known Zambia airports', () => {
    expect(iataToCountry('LUN')).toBe('ZM')
    expect(iataToCountry('LVI')).toBe('ZM')
    expect(iataToCountry('lun')).toBe('ZM') // case-insensitive
  })
  it('maps common regional gateways', () => {
    expect(iataToCountry('JNB')).toBe('ZA')
    expect(iataToCountry('ADD')).toBe('ET')
    expect(iataToCountry('NBO')).toBe('KE')
  })
  it('returns null for unknown codes', () => {
    expect(iataToCountry('XXX')).toBeNull()
    expect(iataToCountry('')).toBeNull()
    expect(iataToCountry(null)).toBeNull()
    expect(iataToCountry(undefined)).toBeNull()
  })
})
