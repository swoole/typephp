import importlib.util
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location(
    "configure_test_options", Path(__file__).resolve().parents[1] / "configure-test-options.py"
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TestOptionsTest(unittest.TestCase):
    def test_default_runs_only_normal_matrix(self):
        options = module.test_options("fix: constant expressions", "refs/heads/master")
        self.assertTrue(options["run_tests"])
        self.assertEqual(options["phpt_matrix"]["include"], [
            {"php": "8.4", "sanitize": ""}, {"php": "8.5", "sanitize": ""}
        ])

    def test_body_flag_selects_only_both_asan_versions(self):
        options = module.test_options("fix: constants\n\n--sanitize\n", "refs/pull/172/merge")
        self.assertEqual(options["phpt_matrix"]["include"], [
            {"php": "8.4", "sanitize": "address"}, {"php": "8.5", "sanitize": "address"}
        ])

    def test_sanitize_does_not_duplicate_phpt_on_push_or_release(self):
        for ref in ("refs/heads/master", "refs/tags/v1.0"):
            options = module.test_options("fix: runtime --sanitize", ref)
            self.assertTrue(options["run_tests"])
            self.assertEqual(len(options["phpt_matrix"]["include"]), 2)
            self.assertTrue(all(entry["sanitize"] == "address" for entry in options["phpt_matrix"]["include"]))

    def test_skip_tests_wins_on_branches_and_pull_requests(self):
        for ref in ("refs/heads/master", "refs/pull/172/merge"):
            self.assertFalse(module.test_options("fix --skip-tests --sanitize", ref)["run_tests"])

    def test_release_tags_always_test(self):
        self.assertTrue(module.test_options("release --skip-tests", "refs/tags/v1.0")["run_tests"])

    def test_markers_are_separate_tokens(self):
        options = module.test_options("x--sanitize --sanitize-later --skip-tests-later", "refs/heads/master")
        self.assertTrue(options["run_tests"])
        self.assertEqual(len(options["phpt_matrix"]["include"]), 2)


if __name__ == "__main__":
    unittest.main()
