# Amharic review

`am-review.csv` lists every Amharic text with its English original (opens in Excel; UTF-8).
Columns: file, key, English, Amharic, status, reviewer note. All rows start as **needs native review**
(the translation is machine-made).

For the reviewer: correct the Amharic column, write a note if needed, and set status to `ok` or `changed`.
For the developer: copy the changed texts back into the file named in the first column (same key), then
run the tests (`npm test` checks the same keys and `{{placeholders}}`; `php artisan test --filter=LandingPageTest`
checks the website texts and `:placeholders`). Make the CSV again with `python3 scripts/am-review-csv.py` (keeps no reviewer notes: merge them first).
