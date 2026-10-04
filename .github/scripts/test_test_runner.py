import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


class TestRunnerTest(unittest.TestCase):
    def run_fixture(self, runner_status=0, discovery_status=0, report=True, parallel=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            scripts = root / '.github/scripts'
            scripts.mkdir(parents=True)
            for name in ['run-tests.sh', 'test-report.py']:
                shutil.copyfile(Path(__file__).with_name(name), scripts / name)
            commands = root / 'commands'
            commands.mkdir()
            (root / 'vendor/bin').mkdir(parents=True)
            phpunit = root / 'vendor/bin/phpunit'
            phpunit.write_text('''#!/usr/bin/env bash
if [[ "$1" == "--list-tests-xml" ]]; then
  if [[ "$DISCOVERY_STATUS" == 0 ]]; then
    echo '<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass><testMethod id="A::test" /></testClass></tests></testSuite>' > build/discovered.xml
  fi
  exit "$DISCOVERY_STATUS"
fi
if [[ "$WRITE_REPORT" == 1 ]]; then
  echo '<testsuites><testsuite><testcase classname="A" name="test" time="0.1" /></testsuite></testsuites>' > build/junit.xml
fi
exit "$RUNNER_STATUS"
''')
            phpunit.chmod(0o755)
            (commands / 'php').write_text('#!/usr/bin/env bash\nexec ./vendor/bin/phpunit --log-junit build/junit.xml\n')
            (commands / 'php').chmod(0o755)
            (commands / 'python3').write_text(f'#!/usr/bin/env bash\nif [[ "$1" == "-m" ]]; then exit 0; fi\nexec "{sys.executable}" "$@"\n')
            (commands / 'python3').chmod(0o755)
            env = os.environ | {'PATH': str(commands) + ':' + os.environ['PATH'],
                                'RUNNER_STATUS': str(runner_status), 'DISCOVERY_STATUS': str(discovery_status),
                                'WRITE_REPORT': '1' if report else '0', 'TEST_PROCESSES': '2' if parallel else '1'}
            return subprocess.run(['bash', str(scripts / 'run-tests.sh')], cwd=root, env=env,
                                  capture_output=True, text=True).returncode

    def test_serial_and_parallel_success(self):
        self.assertEqual(self.run_fixture(), 0)
        self.assertEqual(self.run_fixture(parallel=True), 0)

    def test_runner_failure_survives_tee_and_successful_report(self):
        self.assertEqual(self.run_fixture(runner_status=137), 137)
        self.assertEqual(self.run_fixture(runner_status=2, parallel=True), 2)

    def test_missing_report_fails_even_when_runner_returns_success(self):
        self.assertEqual(self.run_fixture(report=False), 1)

    def test_discovery_failure_preserves_status(self):
        self.assertEqual(self.run_fixture(discovery_status=3), 3)


if __name__ == '__main__':
    unittest.main()
