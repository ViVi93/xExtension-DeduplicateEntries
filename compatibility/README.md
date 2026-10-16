# FilterTitle compatibility patch

`filtertitle-literal-keywords.patch` is a small local compatibility patch for the independently installed **CNTools FilterTitle 0.1.0** extension. It is not required for DeduplicateEntries and is not installed automatically.

The upstream matcher tries every configured string with `preg_match()` before its literal fallback. Plain keywords and malformed regexes therefore emit PHP warnings. The patch preserves literal substring matching and valid delimited regexes; it restores the previous PHP error handler after handling a malformed pattern. It does not change keyword lists or filtering decisions.

Back up the installed FilterTitle directory before applying the patch from your FreshRSS `extensions/` directory:

```sh
patch --dry-run -p1 < /path/to/filtertitle-literal-keywords.patch
patch -p1 < /path/to/filtertitle-literal-keywords.patch
```

Check that your installed source matches 0.1.0 first. An upstream update may already fix this, or may require a different patch. Keep this patch separate from the canonical deduplication implementation.
