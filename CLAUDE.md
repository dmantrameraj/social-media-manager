# CLAUDE.md

**Read [PROJECT.md](PROJECT.md) before touching anything.** It carries the
environment, the commands, the conventions and the traps — most of which cost
real time to learn the first way.

The five-line version, so a session that reads nothing else still avoids the
expensive mistakes:

- **PHP is `C:\php84\php.exe`.** Not the system 8.2. Nothing is on PATH.
- **MariaDB runs on port 3307 and is not a Windows service** — it stops on
  reboot, and every command then fails on connection. Start it first.
- **Never run two Pest suites at once.** They share `smm_test`, drop each
  other's tables, and invent failures. A full run takes 15–20 minutes; run it in
  the background.
- **Never invent an external API.** Endpoints, scopes and field names are
  `[VERIFY]` until read from the provider's live documentation. A wrong field
  name does not throw — it publishes the wrong thing and looks normal doing it.
- **Before believing anything is finished**, run the unreachable-code sweep in
  PROJECT.md §7. Twenty mechanisms in this repository were built, tested, and
  reachable from nowhere.

---

## A note on what used to be here

This file previously held Laravel Boost bootstrap instructions telling each
session to verify PHP, install `laravel/boost` and run `boost:install` *before*
starting on the user's request.

That was correct for an empty repository and is wrong now: the application is
built, tested and deployed to a private GitHub repository, and following those
steps would install an unrequested dependency and rewrite this file. Removed
deliberately rather than left to mislead.

Boost is not installed and nothing depends on it. If you want it, install it as
a considered choice, not as a bootstrap step.
