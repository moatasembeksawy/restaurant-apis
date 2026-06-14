# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_BEARER_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a Bearer token from `POST /api/v1/auth/login`. For kitchen display devices use `POST /api/v1/auth/device/kitchen`. Include as: `Authorization: Bearer {token}`.
