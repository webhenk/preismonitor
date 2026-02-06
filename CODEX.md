# Codex Rules (must follow)

- Always sync with main before changing code (rebase).
- Make minimal diffs. No reformatting.
- One feature per PR.
- If site is dynamic (JS app), do not parse the initial cURL HTML.
  Use Playwright runner and save artifacts: screenshot, rendered.html, body.txt, xhr json.
- When merge conflicts occur: integrate both changes, never "accept current/incoming" blindly.
- Every PR must include a short "How to test" section.
