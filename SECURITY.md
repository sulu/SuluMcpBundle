# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 1.0     | :white_check_mark: |

See also documentation about the [Release Cycle](https://sulu.io/direction#our-release-cycle).

## Reporting a Vulnerability

You can contact us for security related issues by using [security@sulu.io](mailto:security@sulu.io).

Please do not open a public issue for security reports.

## Scope

This bundle exposes Sulu content management over the Model Context Protocol and authenticates through Sulu's own user
system. Reports about the following are especially relevant:

* Any operation reachable over MCP that a user cannot perform in the administration interface.
* Bypasses of the `dangerous_tools` configuration.
* Issues in the OAuth 2.1 authorization flow, the consent screen, or dynamic client registration.
