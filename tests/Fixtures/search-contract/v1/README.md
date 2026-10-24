# Search contract v1 fixtures

Canonical `GET /api/v1/mobile/search?contract_version=1` responses.

- `SearchContractTest` checks the live endpoint returns the same envelope and
  the same keys for every kind as these files.
- spark-ios keeps a copy in `Packages/SparkKit/Tests/SparkKitTests/Fixtures/SearchContract/v1`
  and decodes it in `SearchContractFixtureTests`. Change both together.
- `forward-compat-unknown-kind.json` is not producible by the server; it is the
  file clients use to prove an unknown kind is dropped and the page still renders.
