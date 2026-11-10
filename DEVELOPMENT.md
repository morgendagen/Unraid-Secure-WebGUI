# Development

Notes for working on the plugin: how it is laid out, how to build and test it, and how to make a release.

## Repository layout

| Path | Contents |
| --- | --- |
| `ACME.plg` | The Unraid plugin file: version, changelog, package URL and md5, install and remove scripts. Unraid installs and updates the plugin from the copy on `main`. |
| `src/` | Files installed on the server, as they appear in the package. |
| `src/usr/local/emhttp/plugins/ACME/` | WebGUI pages (`*.page`), PHP (`include/`), JavaScript (`js/`) and images. |
| `src/usr/share/ACME/scripts/` | Hooks run by acme.sh: `reloadcmd` installs the certificate, and `post-hook` reports failures. |
| `scripts/build_package.sh` | Builds the Slackware package (`.txz`). |
| `archive/` | Released packages. The `.plg` downloads them from `main`. |
| `.github/workflows/` | `build.yaml` builds pull requests. `release.yaml` builds releases. |
| `plugins/ACME-plugin.xml`, `ca_profile.xml` | Community Applications listing. Update them when the description changes. |

## On the server

| Path | Contents |
| --- | --- |
| `/usr/local/emhttp/plugins/ACME/` | WebGUI files. |
| `/usr/share/ACME/acme.sh/` | acme.sh, downloaded and pinned by the build. |
| `/usr/share/ACME/scripts/` | The hooks. |
| `/boot/config/plugins/ACME/` | acme.sh config home: `account.conf` (including saved DNS provider options), and one directory per certificate. Kept across reboots and plugin updates. |
| `/boot/config/ssl/certs/<server name>_unraid_bundle.pem` | The WebGUI's nginx certificate bundle, written by `reloadcmd`. |

Everything outside `/boot` is in RAM. On reboot it is reinstalled from the package.

To run acme.sh by hand with the plugin's configuration:

```sh
/usr/share/ACME/acme.sh/acme.sh --config-home /boot/config/plugins/ACME --home /usr/share/ACME/acme.sh --info
```

For troubleshooting, issue a certificate with "Verbose logging" checked. The dialog then shows acme.sh's debug output, and automatic renewals log to the System Log.

## Building the package

The package is built in the `aclemons/slackware:15.0` container, which has `makepkg`:

```sh
docker run --rm -v "$PWD:/workspace" -w /workspace -e VERSION=test \
  aclemons/slackware:15.0 ./scripts/build_package.sh
```

This writes `archive/ACME-test-noarch-1.txz`. Don't commit test packages.

The build:

1. copies `src/`
2. downloads the acme.sh release given by `ACME_VERSION`, and checks it against `ACME_SHA256`
3. generates `dnsapi_info.txt` from the acme.sh dnsapi scripts. The WebGUI reads its list of DNS providers and their options from this file.

### Updating acme.sh

1. Set `ACME_VERSION` in `scripts/build_package.sh`.
2. Set `ACME_SHA256` to the checksum of the new release tarball. Get it over a verified connection, outside the build container, which has no CA certificates:

   ```sh
   curl -fsSL https://github.com/acmesh-official/acme.sh/archive/refs/tags/<version>.tar.gz | shasum -a 256
   ```

3. Build, and check that the DNS provider list and options still look right in the WebGUI.

### Scripts

The scripts run with bash 5 on Unraid. macOS ships bash 3.2, which lacks e.g. `[[ -v name ]]`, so test scripts in the Slackware container instead. Run `shellcheck` on any script you change.

## Running the workflows locally with act

[act](https://github.com/nektos/act) runs the GitHub workflows in Docker. The workflows start the build container themselves, so pass `--bind`, which lets that container see the checkout.

With OrbStack, point act at OrbStack's Docker socket:

```sh
export DOCKER_HOST=unix://$HOME/.orbstack/run/docker.sock
```

### Pull request build

```sh
echo '{"pull_request":{"number":1}}' > /tmp/pr.json
act pull_request -e /tmp/pr.json -W .github/workflows/build.yaml --bind
```

This writes the package to `archive/`, e.g. `ACME-2026.11.08_pr1.1-noarch-1.txz`. Delete it afterwards. The final "Upload package" step fails under act, as act's artifact server doesn't support `actions/upload-artifact` v7. It works on GitHub.

### Release build

```sh
echo '{"release":{"tag_name":"2026.11.08"}}' > /tmp/release.json
act release -e /tmp/release.json -W .github/workflows/release.yaml --bind
```

Use the version in `ACME.plg`, or the version check fails. Without a token, the run stops at "Upload release asset", so nothing is published or pushed. This tests the version check and the build.

## Testing on a server

### Pull request builds

Every pull request is built by `build.yaml`, and the package is kept as an artifact named `ACME-<plg version>_pr<number>.<run>-noarch-1.txz`:

1. Open the pull request's "Checks" tab, open the "Build Package" run, and download the artifact. GitHub delivers it as a zip.
2. Unzip it, copy the `.txz` to the server, and install it. Keep the file name, as `upgradepkg` reads the package name and version from it:

   ```sh
   upgradepkg --install-new --reinstall ACME-<version>-noarch-1.txz
   ```

3. Check which package is installed:

   ```sh
   ls /var/log/packages/ | grep '^ACME-'
   ```

   This should list the pull request build, e.g. `ACME-2026.11.08_pr35.1-noarch-1`. The Plugins page keeps showing the release version, as it reads the version from the installed `ACME.plg`, which `upgradepkg` doesn't change.

This replaces the installed package, but doesn't run the `.plg` install script, which installs the cron job. On a server that already has the plugin installed, the cron job is already there. The next plugin update from the WebGUI installs a release again.

Packages built before the hooks were committed as executable contain them without the executable bit, and rely on the `.plg` install script to `chmod` them. When installing such a package with `upgradepkg`, run `chmod a+x /usr/share/ACME/scripts/*` afterwards, or acme.sh fails with "Permission denied" when installing the certificate.

### Quick iterations

For changes to the WebGUI files or hooks, copy the changed files to their place on the server, e.g. with the VS Code SFTP extension. `.vscode/sftp.json` is ignored by git. The copies are lost on reboot.

When changing JavaScript, bump the `?<n>` cache-busting number where the file is imported. For example, `ACME.js` is imported in `ACME.page` as `ACME.js?14`. Otherwise browsers keep using the old file.

## Branches and pull requests

- One branch and pull request per issue, named `bugfix/<issue>-<short-description>` or `feature/<issue>-<short-description>`, e.g. `bugfix/22-staging-not-installed`.
- End the commit message with `Fixes #<issue>.`, so merging closes the issue.
- Describe the changes and the testing in the pull request. Say whether it was tested on a server.

## Making a release

Versions are dates, `YYYY.MM.DD`. For a second release on the same day, add a letter, e.g. `2026.06.24a`. Unraid offers an update when the version in `ACME.plg` on `main` is newer than the installed one, comparing versions as strings, so keep to this format.

1. Create a branch `release/<version>`.
2. In `ACME.plg`:
   - set the `version` entity to the new version
   - add the changelog at the top of `<CHANGES>`, written for users:

     ```text
     ###2026.11.08
     - Saved DNS provider secrets are no longer sent to the browser.
     - ...
     ```

   Leave the `md5` entity alone. The release workflow updates it.
3. Open a pull request, check the pull request build on a server, and merge.
4. Create a GitHub release from `main`, with the version as the tag (no `v` prefix), and publish it.

Publishing the release runs `release.yaml`, which:

1. checks that the tag matches the version in `ACME.plg`
2. builds the package and uploads it to the release
3. commits the package to `archive/` on `main`, together with its md5 in `ACME.plg`

Users get the update once that commit is on `main`. Check that the workflow succeeded. If it failed after uploading, e.g. because `main` already has a newer version in `ACME.plg`, add `archive/ACME-<version>-noarch-1.txz` and its md5 (`md5sum`) to `main` by hand.
