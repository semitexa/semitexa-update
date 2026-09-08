# Semitexa E2E runner — Playwright execution engine.
#
# Extends the official Microsoft Playwright image (browsers preinstalled)
# with a globally installed @playwright/test pinned to the version in the
# project's package.json. This means a fresh checkout can run E2E tests
# without ever invoking `npm install` on the host or inside the container at
# runtime — the test runner itself ships with the image.
#
# Pin the FROM tag and the npm package version together. Bumping one without
# the other is a defect.
FROM mcr.microsoft.com/playwright:v1.59.1-jammy

ARG PLAYWRIGHT_TEST_VERSION=1.59.1
RUN npm install --global --no-audit --no-fund "@playwright/test@${PLAYWRIGHT_TEST_VERSION}" \
    && npm cache clean --force

# /var/www/html is the canonical project mount path used by the rest of the
# Semitexa Docker setup (matches the app and phpunit services).
WORKDIR /var/www/html
