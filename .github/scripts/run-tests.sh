#!/usr/bin/env bash
set -euo pipefail
python3 -m unittest discover -s .github/scripts -p 'test_*.py'
mkdir -p build
rm -f build/junit.xml build/discovered.xml build/test-results.json
started=$SECONDS
set +e
./vendor/bin/phpunit --list-tests-xml build/discovered.xml
status=$?
if [ "$status" -eq 0 ]; then
    if [ "${TEST_PROCESSES:-2}" -eq 1 ]; then
        ./vendor/bin/phpunit --log-junit build/junit.xml 2>&1 | tee build/test-output.txt
    else
        php artisan test --parallel --processes="${TEST_PROCESSES:-2}" --log-junit=build/junit.xml 2>&1 | tee build/test-output.txt
    fi
    status=${PIPESTATUS[0]}
fi
python3 .github/scripts/test-report.py --runner-status "$status" --elapsed "$((SECONDS - started))"
report_status=$?
set -e
if [ "$status" -ne 0 ]; then
    exit "$status"
fi
exit "$report_status"
