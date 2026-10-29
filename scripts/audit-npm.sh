set -euo pipefail
scan_root=$(mktemp -d)
trap 'rm -rf "$scan_root"' EXIT
curl --fail --silent --show-error --location --retry 2 --max-time 180 https://github.com/google/osv-scanner/releases/download/v2.7.0/osv-scanner_linux_amd64 --output "$scan_root/osv-scanner"
printf '%s  %s\n' 160c6253e2922e9e0a6a9771c5c4c40a4b3c6090b34a3cdd5e80c96cc2e4218b "$scan_root/osv-scanner" | sha256sum --check
chmod +x "$scan_root/osv-scanner"
mkdir -p "$scan_root/database" reports
OSV_SCANNER_LOCAL_DB_CACHE_DIRECTORY="$scan_root/database" "$scan_root/osv-scanner" scan source --offline --download-offline-databases --no-resolve --lockfile package-lock.json --format json --output-file reports/npm-advisories.json
