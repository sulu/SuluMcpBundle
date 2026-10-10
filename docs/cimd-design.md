# Client ID Metadata Documents (CIMD): design notes

## Does a CIMD client need a row in `oauth2_client`?

Yes. In `league/oauth2-server-bundle` the access token, refresh token and authorization
code tables each have a `client` column that is a foreign key on `oauth2_client.identifier`
(`ON DELETE CASCADE`). A client that issues tokens needs a row, or the insert fails.

The identifier column is `CHAR/VARCHAR(32)`, and the foreign key columns inherit that length.
A metadata URL does not fit, and changing the column would force a schema migration onto
every project that installs the bundle.

## Decision

`CimdClientManager` decorates `ClientManagerInterface`. For a `client_id` that is an
`https://` URL it fetches and validates the metadata document, then persists a public client
(no secret, grants `authorization_code` and `refresh_token`) under a stable 32-character
surrogate identifier, `substr(hash('sha256', <url>), 0, 32)`. League only requires that the
identifier is the same on every call, because the entity identifier is what lands in codes and tokens.
Name and redirect URIs are refreshed from the document whenever the cache entry has expired.
Everything else (`save`, `remove`, `list`, non-URL ids) is passed through unchanged, so DCR
and the manual flow keep working.

The consent page recovers the original URL from the `client_id` query parameter of the
authorization request, not from the stored identifier.
