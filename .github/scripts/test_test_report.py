import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('test_report', Path(__file__).with_name('test-report.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TestReportTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)

    def fixture(self, cases, discovered=None):
        self.junit = self.root / 'junit.xml'
        self.discovery = self.root / 'discovered.xml'
        self.junit.write_text('<testsuites><testsuite>' + cases + '</testsuite></testsuites>')
        count = cases.count('<testcase') if discovered is None else discovered
        self.discovery.write_text('<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass>' +
                                  ''.join(f'<testMethod id="test{i}" />' for i in range(count)) +
                                  '</testClass></tests><groups><group><test id="duplicate" /></group></groups></testSuite>')

    def test_counts_class_names_and_datasets_and_separates_outcomes(self):
        self.fixture('''<testcase classname="A" name="same" time="1" assertions="2" />
        <testcase classname="B" name="same" time="2"><failure /></testcase>
        <testcase classname="B" name="same with data set #1" time="3"><error /></testcase>
        <testcase classname="C" name="skipped"><skipped /></testcase>''')
        result = module.report(self.junit, self.discovery, 1, 5)
        self.assertEqual([result[key] for key in ['total', 'passed', 'failed', 'errors', 'skipped']], [4, 1, 1, 1, 1])
        self.assertEqual(result['slow_classes'][0], ('B', 5))
        self.assertEqual(result['assertions'], 2)
        self.assertEqual(result['runner_status'], 1)

    def test_rejects_incomplete_and_empty_reports(self):
        for cases, count in [('<testcase classname="A" name="test" />', 2), ('', 0)]:
            with self.subTest(count=count):
                self.fixture(cases, count)
                with self.assertRaises(ValueError):
                    module.report(self.junit, self.discovery, 0, 0)

    def test_rejects_duplicate_identities(self):
        self.fixture('<testcase classname="A" name="test" /><testcase classname="A" name="test" />')
        with self.assertRaises(ValueError):
            module.report(self.junit, self.discovery, 0, 0)

    def test_missing_reports_cannot_pass(self):
        with self.assertRaises(OSError):
            module.report(self.root / 'missing', self.root / 'missing-discovery', 0, 0)


if __name__ == '__main__':
    unittest.main()
