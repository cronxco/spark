#!/usr/bin/env python3
"""Validate PHPUnit JUnit output and publish counts and timings to Actions."""
import argparse
import collections
import json
import os
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


def report(junit, discovery, runner_status, elapsed):
    expected = ET.parse(discovery).findall('.//{*}testMethod')
    cases = ET.parse(junit).findall('.//testcase')
    if not expected or not cases:
        raise ValueError('Test discovery or execution produced zero tests')
    if len(cases) != len(expected):
        raise ValueError(f'Incomplete report: discovered {len(expected)}, reported {len(cases)}')
    identities = [(case.get('classname'), case.get('name')) for case in cases]
    if len(set(identities)) != len(identities):
        raise ValueError('Duplicate class/test/dataset identities in JUnit report')
    result = dict(total=len(cases), passed=0, failed=0, errors=0, skipped=0,
                  assertions=0, elapsed=elapsed, runner_status=runner_status)
    classes = collections.defaultdict(float)
    tests = []
    for case in cases:
        status = ('errors' if case.find('error') is not None else
                  'failed' if case.find('failure') is not None else
                  'skipped' if case.find('skipped') is not None else 'passed')
        result[status] += 1
        result['assertions'] += int(case.get('assertions', '0'))
        duration = float(case.get('time', '0'))
        classes[case.get('classname', '')] += duration
        tests.append(dict(name=f"{case.get('classname')}::{case.get('name')}", seconds=duration, status=status))
    result['slow_classes'] = sorted(classes.items(), key=lambda item: item[1], reverse=True)[:20]
    result['slow_tests'] = sorted(tests, key=lambda item: item['seconds'], reverse=True)[:20]
    result['unsuccessful_tests'] = [test for test in tests if test['status'] != 'passed']
    return result


def publish(result, directory):
    directory.mkdir(parents=True, exist_ok=True)
    (directory / 'test-results.json').write_text(json.dumps(result, indent=2) + '\n')
    for variable in ['GITHUB_OUTPUT', 'GITHUB_STEP_SUMMARY']:
        if variable not in os.environ:
            continue
        with open(os.environ[variable], 'a') as output:
            if variable == 'GITHUB_OUTPUT':
                for key, value in result.items():
                    if isinstance(value, (int, float)):
                        output.write(f'{key}={value}\n')
            else:
                passed = not (result.get('report_error') or result['runner_status'] or result['failed'] or result['errors'])
                output.write(f"## Tests {'passed' if passed else 'failed'}\n\n")
                output.write('| Total | Passed | Failed | Errors | Skipped | Wall time |\n|---:|---:|---:|---:|---:|---:|\n')
                output.write(f"| {result['total']} | {result['passed']} | {result['failed']} | {result['errors']} | {result['skipped']} | {result['elapsed']:.1f}s |\n\n")
                if result.get('report_error'):
                    output.write(f"Report error: {result['report_error']}\n\n")
                output.write('### Slowest classes (summed test time)\n\n| Class | Seconds |\n|---|---:|\n')
                for name, seconds in result.get('slow_classes', []):
                    output.write(f'| `{name}` | {seconds:.3f} |\n')
                output.write('\nFull test timings and failure details are in the test report artifact.\n')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, default=Path('build'))
    parser.add_argument('--runner-status', type=int, required=True)
    parser.add_argument('--elapsed', type=float, required=True)
    args = parser.parse_args()
    try:
        result = report(args.directory / 'junit.xml', args.directory / 'discovered.xml', args.runner_status, args.elapsed)
    except (OSError, ET.ParseError, ValueError) as error:
        result = dict(total=0, passed=0, failed=0, errors=0, skipped=0,
                      elapsed=args.elapsed, runner_status=args.runner_status, report_error=str(error))
        print(f'::error::{error}', file=sys.stderr)
    publish(result, args.directory)
    return int(bool(result.get('report_error') or args.runner_status or result['failed'] or result['errors']))


if __name__ == '__main__':
    sys.exit(main())
