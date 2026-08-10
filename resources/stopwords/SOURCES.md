# Stopword list sources

IllumiSearch ships minimal "search stopwords" — high-frequency grammatical
words only (articles, pronouns, prepositions, conjunctions, auxiliaries).
Lexical content words (e.g. `help`, `research`, `system`) are never excluded.

| Language | File | Source |
|----------|------|--------|
| English | `english.txt` | NLTK stopwords corpus (Apache-2.0), derived from PostgreSQL/Snowball |
| French | `french.txt` | NLTK stopwords corpus (Apache-2.0), derived from PostgreSQL/Snowball |
| Spanish | `spanish.txt` | NLTK stopwords corpus (Apache-2.0), derived from PostgreSQL/Snowball |
| Portuguese | `portuguese.txt` | NLTK stopwords corpus (Apache-2.0), derived from PostgreSQL/Snowball |
| Arabic | `arabic.txt` | NLTK stopwords corpus (Apache-2.0), derived from PostgreSQL/Snowball |
| Russian | `russian.txt` | NLTK stopwords corpus (Apache-2.0), derived from PostgreSQL/Snowball |
| Chinese | `chinese.txt` | Maintained in-repo: curated list of Chinese function words |

NLTK corpus provenance:
<https://raw.githubusercontent.com/nltk/nltk_data/gh-pages/packages/corpora/stopwords.zip>
(README: lists obtained from PostgreSQL/Snowball stopwords; distributed under
Apache-2.0 by the NLTK project.)

The Chinese list is a curated set of Mandarin function words (particles,
pronouns, prepositions, conjunctions, auxiliaries). It is intentionally
short and free of lexical content words.
