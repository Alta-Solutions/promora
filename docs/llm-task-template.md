# LLM Task Template

Use this template when asking Codex or another coding agent to work on this repo.

```md
Goal:
Describe the desired behavior or bug fix.

Context:
Relevant route, store, product, promotion, or job IDs.

Relevant files:
- app/...
- public/...

Expected behavior:
What should happen after the change.

Current behavior:
What happens now, including concrete examples.

Do not touch:
Any files, workflows, stores, or APIs that should stay unchanged.

Verification:
Commands or manual checks that should pass.
```

## Example

```md
Goal:
Prevent duplicate promotion creation when a user double-clicks submit.

Relevant files:
- app/Controllers/PromotionController.php
- app/Views/promotions/create.php
- public/css/promotions.css

Expected behavior:
Only one promotion is created per form render, even if multiple POST requests are sent.

Verification:
php -l app/Controllers/PromotionController.php
php -l app/Views/promotions/create.php
vendor\bin\phpunit.bat app\Controllers\PromotionControllerSubmissionTokenTest.php
```
