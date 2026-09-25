# WIC Training Platform (MVP)

A WordPress plugin for WIC staff training across multiple agencies. It follows the build order in *Functionality and Build Guide* (18 Sep 2026): records that survive first, then the course structure, the lesson player with its three interaction components, and the position, attempt and completion records. After that come the three screens people respond to: the certificate, the overdue list and the agency export.

Built fresh. The Delaware portal code was not available, so its registration and approval features were rebuilt here, with the known defects fixed from the start.

## Install

1. Copy `wic-training-platform/` into `wp-content/plugins/`.
2. Activate **WIC Training Platform**.
3. Set permalinks to *Post name* (Settings → Permalinks).
4. Go to **WIC Platform → Agency settings** and set the name, logo, colours and certificate prefix.
5. Optional: **WIC Platform → Overview → Create demo accounts** adds a supervisor and two learners so you can try every view.

Activation creates 7 database tables, 4 roles and 5 pages, and seeds a sample course (*Portal Orientation*). The sample course uses every layout, layers, all five question types, a graded module and a certificate.

| Page | Shortcode | What it is |
|---|---|---|
| Training Portal | `[wic_portal]` | Sign-in; the learner, supervisor and administrator views |
| Register | `[wic_register]` | Self-registration with supervisor choice |
| Lesson | `[wic_player]` | The lesson player, as a standalone page (`?course=ID&slide=ID`) |
| Certificate | `[wic_certificate]` | Printable certificate (`?code=…`) |
| Verify a Certificate | `[wic_verify]` | Public check page. No account needed |

## What is in this build

**Accounts and roles**
- Roles: Administrator, Supervisor, Learner and Content Author.
- Staff and Intern are a group on the account, not separate roles.
- Every account has a reports-to line.
- Self-registration with supervisor choice.
- A different response to a duplicate email depending on whether that account is pending, active, rejected or closed.
- The approver sets the group, not the registrant.
- On approval the person gets a one-time set-password link.
- **Deactivate, never delete.** The delete action is blocked for any WIC user, and closed accounts can't sign in.
- Scheduled deactivation from a leaving date.
- Test accounts can be hidden from the registration supervisor list.
- Changing group re-runs assignment rules and only ever adds courses.

**Authoring** (WordPress content types: Course → Module → Slide)
- Ordering, revisions and preview come from WordPress.
- Course settings: required, auto-assign by group, due within N days, pass mark, free or linear navigation, auto-advance, credit type and hours, recertification period, owner.
- Course versions; each assignment and completion records the version it was on.
- Duration is calculated from the slides, not typed in.
- Slide settings: layout (text, image, callout or question), image and alt text, narration audio URL and length, one narration script (it feeds the transcript), layers (JSON), question (JSON).
- **Publishing is blocked** when an image has no alt text or its alt text looks like a filename.

**Lesson player**
- The Outline and Transcript sidebar is open by default.
- Deep links by slide ID (`?slide=`).
- Resume on any device; the position is saved on the server on every slide change.
- Progress is counted by furthest slide reached, so reviewing never lowers it.
- Time remaining, and time spent with idle time capped.
- Narration with playback speed and optional auto-advance.
- Layers are state inside the slide, not extra slides.
- Lightbox to enlarge images.
- Full-screen toggle.
- Keyboard: ← → to move between slides, K to play or pause.
- Focus moves to the slide heading on each change, and feedback is announced to screen readers.
- Save-and-exit; restarting starts a new run and keeps the old record.

**Knowledge checks**
- Multiple choice, which also covers true/false and multiple response.
- Drag-and-drop sorting and matching, both built as **select-then-place**, so they work with keyboard, touch and screen readers.
- Scored on the **server**; correct answers are never sent to the browser.
- Points and attempts set per question (default 10 points, 2 attempts).
- Feedback per option, falling back to a question-level message.
- A hint behind a control.
- A wrong answer links back to the slide that explains it.
- After the last attempt, the correct answer is shown.
- Every answer is stored as its own row.

**Records and certificates**
- Status is derived, never stored: not started, in progress, overdue, complete or expired.
- Completion is decided on the server: every slide seen and every graded module passed.
- Certificate snapshot: name, course, date, score, hours, credit type, agency-prefixed number and a random verification code with QR.
- Expiry, revocation, and a check page that says *revoked* rather than *not found*.
- The certificate is rendered from the record on demand; screen and print use the same template.

**Supervisor and administrator views**
- Team progress.
- **Overdue list** sorted by days late, with *Remind everyone* (queued, and logged) and extend-with-reason.
- Approvals scoped to the supervisor's own people.
- Approval queue size and oldest wait per supervisor, with automatic escalation after N days.
- Assign to a person, a group or everyone.
- Move a whole team; progress is untouched.
- Training matrix.
- One CSV export engine for both the team and the agency, with protection against spreadsheet formula injection.
- In WP-admin: completion by clinic, most-missed questions, certificates, audit log.

**Platform**
- One agency configuration record for name, logo, colours (design tokens), certificate prefix, signatory, pass mark, reminder and escalation days, and a dated announcement banner.
- One events store feeds the notification centre and the emails.
- One hourly scheduled job handles the send queue, due-soon and overdue notices, escalation and scheduled deactivation.

## Version 0.2: the merged functionality list (v3, 24 Sep 2026)

Every Part 1 item of *WIC Portal Merged Functionality List v3* is now built, as feature modules in `includes/modules/`. Each module registers its own tables, pages, roles, settings and portal views through hooks, so they can be read and changed independently.

The portal is grouped into **My learning**, **My team**, **Agency** and **Authoring**. People see only the groups their role allows. The roles are Administrator, Local administrator, Supervisor, Learner, Content author and Vendor.

**Decision-first items are built but switched off.** They are listed under *WIC Platform → Agency settings → Open decisions*: single sign-on, content ownership, sharing between agencies, agency comparison, retake rules, test out, generated narration, record retention and the portable record. Turn one on only once it has been agreed.

**What still needs a person:**
- Spanish UI (`languages/wic-tp-es_ES.l10n.php`) needs review by a fluent speaker before release. Hard-coded English in `player.js` is not translated yet.
- Screen-reader testing, the accessibility statement and the conformance report record results that a person has entered. Until then they say "not yet tested".
- Telehealth, remote-appointment and out-of-state transfer guidance are "(Content needed)" placeholders for the agency to write.
- These are built but untested against real systems: single sign-on (OIDC), the xAPI feed, the cmi5 launch and export, and the HR sync.
- The Arizona Storyline output parser needs those source folders. The JSON and CSV course importers are in place.

Part 2 of the list (public website and participant portal) is out of scope, as the list says.

## Earlier notes: not in the first MVP

- Question banks, shuffling questions (answers are already shuffled), pre-tests, cooling-off between attempts.
- Competency map, prerequisites, learning paths, exemptions, external training.
- Generated narration pipeline: TTS, pronunciation dictionary, stale-audio hashing, word timings. **Blocked:** approval for AI narration is still open (asked 3 Sep).
- Captions (WebVTT).
- A second language on slides.
- Search across transcripts.
- Server-side PDF (browser *Save as PDF* is used for now).
- Weekly digest.
- SMS.
- Multi-agency tenancy (shared library with fork-on-edit).
- The top three from *New Functionality*:
  - #36 e-signature compliance forms
  - #9 competency checklist signed by a preceptor
  - #11 course-to-requirement crosswalk (best added to the schema early)

## Notes

- The QR code on certificates loads `qrcodejs` from cdnjs. If that's blocked, the printed code and URL still work.
- Remove the demo accounts before going live. Like everyone else, they can only be deactivated.
