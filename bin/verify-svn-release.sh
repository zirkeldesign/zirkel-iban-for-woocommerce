#!/usr/bin/env bash
# Checks whether a release reached WordPress.org SVN intact: tags/<version>
# must hold exactly the files of the given build directory, and trunk must
# match the tag.
#
# The deploy workflow runs this when the SVN commit reports a failure. Large
# commits regularly end in "Connection reset by peer" or a missing transaction
# file AFTER the server has accepted them, so the error alone does not say
# whether the release is missing.
#
# Usage: bin/verify-svn-release.sh <slug> <version> <build-dir>
# Exits 0 when the release is complete, 1 when it is not.
set -euo pipefail

slug="${1:?usage: $0 <slug> <version> <build-dir>}"
version="${2:?usage: $0 <slug> <version> <build-dir>}"
build_dir="${3:?usage: $0 <slug> <version> <build-dir>}"

base="https://plugins.svn.wordpress.org/${slug}"
workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

# The commit can become visible a little after the client gave up on it.
for attempt in 1 2 3 4 5; do
    if svn export -q "${base}/tags/${version}" "${workdir}/tag" 2>/dev/null; then
        break
    fi

    rm -rf "${workdir}/tag"

    if [ "$attempt" -eq 5 ]; then
        echo "tags/${version} does not exist in SVN."
        exit 1
    fi

    echo "tags/${version} not visible yet (attempt ${attempt}/5); retrying in 30s."
    sleep 30
done

if ! diff -rq "$build_dir" "${workdir}/tag"; then
    echo "tags/${version} differs from the build."
    exit 1
fi

if [ -n "$(svn diff --summarize "${base}/trunk" "${base}/tags/${version}")" ]; then
    echo "trunk differs from tags/${version}."
    exit 1
fi

echo "tags/${version} and trunk match the build ($(find "${workdir}/tag" -type f | wc -l | tr -d ' ') files)."
