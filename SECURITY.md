# Security Policy

## Reporting a vulnerability

Please report security problems **privately, by email**, rather than opening a public GitHub issue or pull request:

**sysop@maddogproductions.online**

Please do not post the details anywhere public until a fixed version has been released, so that church websites running the plugin are not exposed in the meantime.

### What to include

The more of this you can give, the faster it can be fixed:

- what the problem is, and what an attacker could do with it
- the steps to reproduce it, ideally with the exact request, URL, form or shortcode page involved
- the plugin version (shown on the Plugins screen and at the top of *Library - Settings > Installation health*)
- WordPress and PHP versions, the web server (Apache, nginx, LiteSpeed ...) and anything unusual about the site
- whether the attacker needs to be logged in, and with which role or library permission
- any proof-of-concept, patch or suggested fix you have

Please do not include real borrower names, email addresses or other personal data from a live site in a report.

### What to expect

This is a volunteer project maintained by one person, so please be patient. The aim is to acknowledge a report within a week, agree on what the problem is and how serious it is, release a fixed version, and credit you in the changelog if you would like that. No bounty is offered.

## Supported versions

Only the latest release receives security fixes. Please update before reporting, and check that the problem is still there.

## How the plugin handles input

The approach is summarised in the *Security* section of [README.md](README.md#security): prepared SQL with whitelisted identifiers, action-bound nonces plus capability checks on every state-changing request, sanitising and length-limiting all input, escaping all output, re-encoding uploaded images, CSV formula neutralising, and https-only outbound requests to fixed, allow-listed hosts. That list is what to check, not a claim that the code is flawless.

## If you deploy from a git clone or a GitHub "Download ZIP"

Install a release zip, or build one as [Building the install zip](README.md#building-the-install-zip) describes. A clone or a raw repository download puts development files in your web root (a clone also brings the `.git` folder with the project history) and installs into a folder named after the branch, such as `church-library-main`.

The plugin defends against that anyway:

- every PHP file exits unless WordPress loaded it (`ABSPATH`, or `WP_UNINSTALL_PLUGIN` for `uninstall.php`; the build script refuses to run except from the command line)
- every folder has an `index.php` so it cannot be listed
- every folder of PHP files has a deny-all `.htaccess`, `assets/` serves only CSS, JS and images, and the top-level `.htaccess` refuses hidden paths such as `.git/` and non-asset file types (`.md`, `.sql`, `.json`, `.log`, `.zip` ...)
- *Library - Settings > Installation health* warns about a branch-named folder, a second copy, a `.git` folder, files that are not part of the release and missing protection files, and its **Run web checks** button tests whether this server really refuses those files

`.htaccess` is Apache-only. On nginx, add the rules in [README.md](README.md#nginx).
