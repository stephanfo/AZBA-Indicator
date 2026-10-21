# extra/version.py
# PlatformIO pre-script: defines FW_VERSION for the firmware and writes it to
# <build dir>/version.txt, where site/build.sh reads it for the web-installer
# manifest. One recipe, so the device and the page always agree.
#
# Version: the FW_VERSION environment variable if set, else
# "<last commit date>-<git describe>" (e.g. "2026.10.21-35f281d", with
# "-dirty" for uncommitted changes), else "dev".

import os
import subprocess
from pathlib import Path

Import("env")  # noqa: F821 — provided by PlatformIO/SCons


def git(*args):
    return subprocess.check_output(
        ["git", *args],
        cwd=env.subst("$PROJECT_DIR"),  # noqa: F821
        stderr=subprocess.DEVNULL,
        text=True,
    ).strip()


version = os.environ.get("FW_VERSION", "").strip()
if not version:
    try:
        date = git("log", "-1", "--format=%cd", "--date=format:%Y.%m.%d")
        version = f"{date}-{git('describe', '--tags', '--always', '--dirty')}"
    except (OSError, subprocess.CalledProcessError):
        version = "dev"

env.Append(CPPDEFINES=[("FW_VERSION", env.StringifyMacro(version))])  # noqa: F821

build_dir = Path(env.subst("$BUILD_DIR"))  # noqa: F821
build_dir.mkdir(parents=True, exist_ok=True)
(build_dir / "version.txt").write_text(version + "\n")
print(f"Firmware version: {version}")
