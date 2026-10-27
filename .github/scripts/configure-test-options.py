#!/usr/bin/env python3
"""Choose normal or ASan PHPT jobs from the tested commit message."""

import json
import os
import re
import subprocess


def test_options(message, ref):
    flags = set(re.findall(r"(?<!\S)--(?:skip-tests|sanitize)(?!\S)", message))
    run_tests = ref.startswith("refs/tags/") or "--skip-tests" not in flags
    sanitizer = "address" if "--sanitize" in flags else ""
    entries = [{"php": version, "sanitize": sanitizer} for version in ("8.4", "8.5")]
    return {"run_tests": run_tests, "phpt_matrix": {"include": entries}}


def main():
    message = subprocess.check_output(["git", "log", "-1", "--format=%B"], text=True)
    options = test_options(message, os.environ.get("GITHUB_REF", ""))
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        output.write("run_tests=" + str(options["run_tests"]).lower() + "\n")
        output.write("phpt_matrix=" + json.dumps(options["phpt_matrix"], separators=(",", ":")) + "\n")
    print(json.dumps(options, indent=2))


if __name__ == "__main__":
    main()
