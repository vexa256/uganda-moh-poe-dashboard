// ICAO 9303 MRZ check-digit computation. Stub implementation — the original
// (untracked) mrzChecksum.js was lost in a git stash/checkout dance during
// the IDSR verification session. Restore from editor history when convenient.
//
// This stub implements the real ICAO 9303 algorithm so MRZ scanning still
// works at correctness level — only the parser wrappers (mrzMultilingual,
// mrzRobust) are stubbed to no-op pass-through.

const WEIGHTS = [7, 3, 1];

function charValue(ch) {
  if (ch >= '0' && ch <= '9') return ch.charCodeAt(0) - 48;
  if (ch >= 'A' && ch <= 'Z') return ch.charCodeAt(0) - 55;
  return 0; // '<' and anything else
}

export function computeCheckDigit(input) {
  if (!input || typeof input !== 'string') return 0;
  let sum = 0;
  for (let i = 0; i < input.length; i++) {
    sum += charValue(input[i].toUpperCase()) * WEIGHTS[i % 3];
  }
  return sum % 10;
}

function checkOne(input, expected) {
  if (expected == null || expected === '<') return null;
  const exp = parseInt(expected, 10);
  if (Number.isNaN(exp)) return null;
  return computeCheckDigit(input) === exp;
}

export function verifyTD3(line1, line2) {
  if (typeof line2 !== 'string' || line2.length < 44) {
    return { ok: false, reason: 'short_line', fields: {} };
  }
  const passportNumber  = line2.substring(0, 9);
  const passportCheck   = line2.charAt(9);
  const dob             = line2.substring(13, 19);
  const dobCheck        = line2.charAt(19);
  const expiry          = line2.substring(21, 27);
  const expiryCheck     = line2.charAt(27);
  const personal        = line2.substring(28, 42);
  const personalCheck   = line2.charAt(42);
  const composite       = line2.substring(0, 10) + line2.substring(13, 20) +
                          line2.substring(21, 28) + line2.substring(28, 43);
  const compositeCheck  = line2.charAt(43);
  return {
    ok: true,
    fields: {
      passport_number: checkOne(passportNumber, passportCheck),
      date_of_birth:   checkOne(dob, dobCheck),
      expiry_date:     checkOne(expiry, expiryCheck),
      personal_number: checkOne(personal, personalCheck),
      composite:       checkOne(composite, compositeCheck),
    },
  };
}

export function verifyTD2(line1, line2) {
  if (typeof line2 !== 'string' || line2.length < 36) {
    return { ok: false, reason: 'short_line', fields: {} };
  }
  const docNumber       = line2.substring(0, 9);
  const docCheck        = line2.charAt(9);
  const dob             = line2.substring(13, 19);
  const dobCheck        = line2.charAt(19);
  const expiry          = line2.substring(21, 27);
  const expiryCheck     = line2.charAt(27);
  const composite       = line2.substring(0, 10) + line2.substring(13, 20) +
                          line2.substring(21, 28) + line2.substring(28, 35);
  const compositeCheck  = line2.charAt(35);
  return {
    ok: true,
    fields: {
      document_number: checkOne(docNumber, docCheck),
      date_of_birth:   checkOne(dob, dobCheck),
      expiry_date:     checkOne(expiry, expiryCheck),
      composite:       checkOne(composite, compositeCheck),
    },
  };
}

export function verifyTD1(line1, line2, line3) {
  // TD1: 3 lines of 30 chars (e.g. ID cards). line3 is name line — no checks.
  if (typeof line1 !== 'string' || typeof line2 !== 'string' ||
      line1.length < 30 || line2.length < 30) {
    return { ok: false, reason: 'short_line', fields: {} };
  }
  const docNumber       = line1.substring(5, 14);
  const docCheck        = line1.charAt(14);
  const dob             = line2.substring(0, 6);
  const dobCheck        = line2.charAt(6);
  const expiry          = line2.substring(8, 14);
  const expiryCheck     = line2.charAt(14);
  const composite       = line1.substring(5, 30) + line2.substring(0, 7) +
                          line2.substring(8, 15) + line2.substring(18, 29);
  const compositeCheck  = line2.charAt(29);
  return {
    ok: true,
    fields: {
      document_number: checkOne(docNumber, docCheck),
      date_of_birth:   checkOne(dob, dobCheck),
      expiry_date:     checkOne(expiry, expiryCheck),
      composite:       checkOne(composite, compositeCheck),
    },
  };
}
