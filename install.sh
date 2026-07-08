#!/usr/bin/env bash
#
# skilltest installer.
#
# Downloads the released skilltest PHAR, verifies its SHA-256 checksum, and
# installs it as an executable named `skilltest`.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/alexskrypnyk/skilltest/main/install.sh | bash
#
# Version resolution (first match wins):
#   1. SKILLTEST_VERSION environment variable.
#   2. A `.skilltest-version` file in the current directory.
#   3. The latest published release.
#
# Environment overrides:
#   SKILLTEST_REPO              owner/name         (default: alexskrypnyk/skilltest)
#   SKILLTEST_RELEASE_BASE_URL  release base URL   (default: GitHub releases for the repo)
#   SKILLTEST_INSTALL_DIR       install directory  (default: /usr/local/bin, else ~/bin)
#   SKILLTEST_VERSION           pin a specific release tag

set -euo pipefail

readonly MIN_PHP_VERSION_ID=80300
readonly MIN_PHP_LABEL='8.3'
readonly PHAR_NAME='skilltest.phar'
readonly CHECKSUMS_NAME='skilltest.phar.sha256'
readonly BIN_NAME='skilltest'

# Partial-download paths, cleaned up by the EXIT trap. Global so the trap can
# see them after main() returns; empty until main() assigns them.
phar_tmp=''
sums_tmp=''

info() { printf 'skilltest: %s\n' "$*"; }
warn() { printf 'skilltest: %s\n' "$*" >&2; }
fatal() {
  printf 'skilltest: error: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  [ -n "${phar_tmp}" ] && rm -f "${phar_tmp}"
  [ -n "${sums_tmp}" ] && rm -f "${sums_tmp}"
  return 0
}

have() { command -v "$1" >/dev/null 2>&1; }

# Abort early unless PHP is present and new enough to run the PHAR.
check_php() {
  if ! have php; then
    fatal "PHP ${MIN_PHP_LABEL} or newer is required but no 'php' binary was found on PATH. Install PHP ${MIN_PHP_LABEL}+ and re-run this installer."
  fi

  if ! php -r 'exit(PHP_VERSION_ID >= '"${MIN_PHP_VERSION_ID}"' ? 0 : 1);' 2>/dev/null; then
    local detected
    detected="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')"
    fatal "PHP ${MIN_PHP_LABEL} or newer is required, but PHP ${detected} was found. Upgrade PHP to ${MIN_PHP_LABEL}+ and re-run this installer."
  fi
}

# Resolve the release tag to install, or empty for the latest release.
resolve_version() {
  if [ -n "${SKILLTEST_VERSION:-}" ]; then
    printf '%s' "${SKILLTEST_VERSION}"
    return 0
  fi

  if [ -f .skilltest-version ]; then
    local pinned
    pinned="$(sed -e 's/#.*//' -e 's/[[:space:]]//g' .skilltest-version | grep -v '^$' | head -n 1 || true)"
    printf '%s' "${pinned}"
    return 0
  fi

  printf ''
}

# Download a URL to a destination path using curl or wget.
download() {
  local url="$1" dest="$2"

  if have curl; then
    curl -fsSL -o "${dest}" "${url}" || fatal "download failed: ${url}"
    return 0
  fi

  if have wget; then
    wget -qO "${dest}" "${url}" || fatal "download failed: ${url}"
    return 0
  fi

  fatal "neither 'curl' nor 'wget' is available; install one and re-run this installer."
}

# Print the SHA-256 hex digest of a file.
sha256_of() {
  local file="$1"

  if have sha256sum; then
    sha256sum "${file}" | awk '{print $1}'
    return 0
  fi

  if have shasum; then
    shasum -a 256 "${file}" | awk '{print $1}'
    return 0
  fi

  fatal "no SHA-256 tool found ('sha256sum' or 'shasum'); cannot verify the download."
}

# Choose a writable install directory, preferring a system location.
pick_install_dir() {
  if [ -n "${SKILLTEST_INSTALL_DIR:-}" ]; then
    mkdir -p "${SKILLTEST_INSTALL_DIR}" 2>/dev/null || fatal "cannot create install directory: ${SKILLTEST_INSTALL_DIR}"
    printf '%s' "${SKILLTEST_INSTALL_DIR}"
    return 0
  fi

  if [ -w /usr/local/bin ]; then
    printf '/usr/local/bin'
    return 0
  fi

  mkdir -p "${HOME}/bin" 2>/dev/null || fatal "cannot create fallback install directory: ${HOME}/bin"
  printf '%s' "${HOME}/bin"
}

main() {
  check_php

  local repo base version
  repo="${SKILLTEST_REPO:-alexskrypnyk/skilltest}"
  base="${SKILLTEST_RELEASE_BASE_URL:-https://github.com/${repo}/releases}"
  version="$(resolve_version)"

  local phar_url sums_url
  if [ -n "${version}" ]; then
    info "installing skilltest ${version}"
    phar_url="${base}/download/${version}/${PHAR_NAME}"
    sums_url="${base}/download/${version}/${CHECKSUMS_NAME}"
  else
    info "installing the latest skilltest release"
    phar_url="${base}/latest/download/${PHAR_NAME}"
    sums_url="${base}/latest/download/${CHECKSUMS_NAME}"
  fi

  local install_dir
  install_dir="$(pick_install_dir)"

  # Download beside the final target so a failed verification never installs
  # anything and no system temp directory is touched.
  phar_tmp="${install_dir}/.${BIN_NAME}.$$.download"
  sums_tmp="${install_dir}/.${BIN_NAME}.$$.sha256"
  trap cleanup EXIT

  download "${phar_url}" "${phar_tmp}"
  download "${sums_url}" "${sums_tmp}"

  local expected actual
  expected="$(grep -i "  ${PHAR_NAME}\$" "${sums_tmp}" | awk '{print $1}' | head -n 1 || true)"
  if [ -z "${expected}" ]; then
    expected="$(awk 'NR==1 {print $1}' "${sums_tmp}")"
  fi
  actual="$(sha256_of "${phar_tmp}")"

  if [ -z "${expected}" ] || [ "${expected}" != "${actual}" ]; then
    fatal "checksum verification FAILED for ${PHAR_NAME} (expected '${expected:-<none>}', got '${actual}'). The download may be corrupt or tampered with; nothing was installed."
  fi

  local target="${install_dir}/${BIN_NAME}"
  chmod +x "${phar_tmp}"
  mv -f "${phar_tmp}" "${target}"

  info "installed ${BIN_NAME} to ${target}"

  case ":${PATH}:" in
    *":${install_dir}:"*) ;;
    *) warn "${install_dir} is not on your PATH; add it, e.g. export PATH=\"${install_dir}:\$PATH\"" ;;
  esac

  "${target}" version
}

main "$@"
