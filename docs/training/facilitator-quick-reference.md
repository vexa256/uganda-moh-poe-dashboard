# Facilitator Quick Reference — Apps & Dashboards

Print one copy per facilitator. Keep it on the table during the session.

---

## Two builds. Two servers. One rule.

| | **Training** *(use this in the room)* | **Live / Production** *(never in the room)* |
|---|---|---|
| App name on the launcher | **Training Uganda POE Screening** | Uganda POE Screening |
| Package ID | `ug.moh.poesentinel.training` | `ug.moh.poesentinel` |
| App connects to | Training server (con-dev2) | Ministry of Health server |
| Dashboard URL | `https://ug-poe.ecsahc.com/admin` | `https://poes.health.go.ug/admin` |
| APK download | `https://ug-poe.ecsahc.com/apks/ug-poe-training-v1.0.0-signed.apk` | `https://poes.health.go.ug/apks/ug-poe-production-v1.0.0-signed.apk` |
| What it is for | Safe practice. Capture, refer, close, repeat. Nothing here is real. | Real travellers, real cases, real alerts. Day-to-day operations only. |

The two builds install **side-by-side** on the same phone. They never share data.

---

## The thirty-second visual check

Before any trainee taps anything, look at the launcher icon. The label
must read **Training Uganda POE Screening**.

If it does not say *Training* — close the app. That device is on the
live system. Uninstall, sideload the correct APK, sign in again.

---

## Sign-in

- **Training:** username + password from each trainee's participant card.
- **Live:** never used during the session.

If a trainee accidentally signs in to the live app, they sign out,
uninstall, and switch. Do not capture anything in the live app to "test
that it works" — that creates a real alert at a real POE.

---

## When something is not behaving

1. Sync stuck amber for over a minute → tap the sync icon once, wait 10 seconds.
2. Still amber → turn Wi-Fi off and back on once.
3. Still amber → swap the phone for a spare and continue the lesson.
4. Login fails on multiple devices → open `https://ug-poe.ecsahc.com/up`
   in a browser. If that does not return *OK*, escalate to the lead
   trainer immediately; do not keep troubleshooting on phones.
5. Dashboard will not load → use the lead trainer's credentials only;
   never re-issue trainee credentials mid-session.

---

## One sentence to remember

The app captures, the dashboard reports — **same data, two views**.
The trainee learns the app. The supervisor reads the dashboard.
