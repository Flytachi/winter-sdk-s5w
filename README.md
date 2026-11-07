# Winter MUI Data Grid

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-mui-data-grid.svg)](https://packagist.org/packages/flytachi/winter-mui-data-grid)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-mui-data-grid.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-mui-data-grid)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

Server-side adapter that connects [MUI X DataGrid](https://mui.com/x/react-data-grid/)
to the [Winter Framework](https://winterframe.net).

You declare a **column schema** — which fields may be filtered and sorted, and how
they map to SQL. The library takes the grid's request (page, sort model, filter
model), enforces that schema as a whitelist, builds a parameterized query on top
of your repository, and returns a `{ rowCount, rows }` payload the DataGrid
understands. SQL-injection safe by construction.

```php
$schema = GridSchema::make(
    GridColumn::for('title',      'a.title')->filterable(FilterType::String)->sortable(),
    GridColumn::for('views',      'a.views')->filterable(FilterType::Number)->sortable(),
    GridColumn::for('authorName', 'au.name')->filterable(FilterType::String)->sortable(),
)->defaultOrder('a.created_at DESC');

return MuiGrid::wrap($repo, $request, $schema)->toArray();
```

---

## Requirements

- [flytachi/winter-kernel](https://packagist.org/packages/flytachi/winter-kernel) —
  the request layer that hydrates and validates the grid request
- [flytachi/winter-ppa](https://packagist.org/packages/flytachi/winter-ppa) —
  the data layer this builds on: repositories, the query builder and the paginator

| This package | winter-kernel | winter-ppa | PHP |
|---|---|---|---|
| `3.x` | `^4.0` | `^1.0` | `>= 8.4` |
| `2.x` | `^3.0` | *(shipped inside the kernel)* | `>= 8.4` |

Coming from 2.x? The namespaces moved with Winter 4 — see
[Upgrading](docs/07-upgrading.md).

## Installation

```bash
composer require flytachi/winter-mui-data-grid
```

Requires PHP **8.4+**; both Winter packages are installed for you by Composer.

### Database support

PostgreSQL, MySQL and SQLite. MUI's case-insensitive string operators
(`contains`, `notContains`, `startsWith`, `endsWith`) have no portable spelling in
SQL — PostgreSQL has `ILIKE`, the other two don't (there it's a syntax error) — so
the schema resolves it from the repository's driver and you write nothing:

```php
GridSchema::make(/* … */);                              // Auto — detected per connection
GridSchema::make(/* … */)->textMatch(TextMatch::Like);  // …or pin it yourself
```

| `TextMatch` | SQL | For |
|---|---|---|
| `Auto` *(default)* | resolved from the repository's driver | anything |
| `ILike` | `col ILIKE :v` | PostgreSQL |
| `Like` | `col LIKE :v` | MySQL / SQLite (a `*_ci` collation decides the case) |
| `Lower` | `lower(col) LIKE lower(:v)` | any engine; also folds non-ASCII |

Every other operator is standard SQL and renders the same everywhere. Details:
[Filtering → database support](docs/03-filtering.md#database-support).

---

## How it works (60 seconds)

A grid request has three moving parts: **pagination**, **sorting** and
**filtering**. This library handles all three around a query you build yourself:

```
   ┌─────────────────────── your code ───────────────────────┐
   │ build repo: SELECT + JOINs + base WHERE                  │
   │ declare GridSchema: field → SQL, filter type, sortable   │
   └──────────────────────────┬──────────────────────────────┘
                              │  MuiGrid::wrap(repo, request, schema, mapper?)
   ┌──────────────────────────▼──────────────────────────────┐
   │ library:                                                 │
   │  • validate filters against the schema (whitelist)       │
   │  • append WHERE (filters) + ORDER BY (sort) to your repo  │
   │  • run COUNT + paginated SELECT                           │
   │  • map each row (optional) → MuiGridResponse{rowCount,rows}│
   └─────────────────────────────────────────────────────────┘
```

The schema is the contract: a field the frontend references that you did **not**
declare is rejected (for filtering) or ignored (for sorting). Operators are gated
by each column's `FilterType`, so a text operator can't be sent against a numeric
column.

---

## Quick start

A minimal "list articles" endpoint. Two tables: `articles a` joined to `authors au`.

### 1. Request

Extend `MuiGridRequest`. The grid envelope (`page`, `pageSize`, `sortModel`,
`filterModel`) is hydrated for you by the Winter request layer — add only your own
domain filters:

```php
use Flytachi\Winter\Kernel\Http\Request\Validation\ListOf;
use Flytachi\Winter\Kernel\Http\Request\Validation\Valid;
use Flytachi\Winter\MuiDataGrid\Entity\MGFilterModel;
use Flytachi\Winter\MuiDataGrid\Entity\MGSortItem;
use Flytachi\Winter\MuiDataGrid\MuiGridRequest;

class ArticleGridRequest extends MuiGridRequest
{
    public function __construct(
        public ?int $authorId = null,            // a domain filter of your own

        int $page = 0,
        int $pageSize = 20,
        #[ListOf(MGSortItem::class)] array $sortModel = [],
        #[Valid] MGFilterModel $filterModel = new MGFilterModel(),
    ) {
        parent::__construct($page, $pageSize, $sortModel, $filterModel);
    }
}
```

### 2. Service

You own the query; the library overlays the grid concerns:

```php
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\MuiDataGrid\MuiGrid;
use Flytachi\Winter\MuiDataGrid\MuiGridResponse;
use Flytachi\Winter\MuiDataGrid\Schema\FilterType;
use Flytachi\Winter\MuiDataGrid\Schema\GridColumn;
use Flytachi\Winter\MuiDataGrid\Schema\GridSchema;

class ArticleService
{
    public function grid(ArticleGridRequest $request): MuiGridResponse
    {
        $repo = ArticleRepository::instance('a')
            ->select('a.id, a.title, a.views, a.created_at, au.name author_name')
            ->joinLeft(AuthorRepository::instance('au'), 'au.id = a.author_id')
            ->where(Qb::eq('a.is_published', true));

        if ($request->authorId) {
            $repo->andWhere(Qb::eq('a.author_id', $request->authorId));
        }

        $schema = GridSchema::make(
            GridColumn::for('title',      'a.title')->filterable(FilterType::String)->sortable(),
            GridColumn::for('views',      'a.views')->filterable(FilterType::Number)->sortable(),
            GridColumn::for('authorName', 'au.name')->filterable(FilterType::String)->sortable(),
            GridColumn::for('createdAt',  'a.created_at')->filterable(FilterType::Date)->sortable(),
        )->defaultOrder('a.created_at DESC');

        return MuiGrid::wrap($repo, $request, $schema);
    }
}
```

### 3. Controller

```php
#[PostMapping]
public function grid(
    #[RequestJson, Valid] ArticleGridRequest $request
): ResponseEntity {
    return ResponseEntity::ok(
        $this->service->grid($request)->toArray()
    );
}
```

### 4. The request the frontend sends

```json
{
  "page": 0,
  "pageSize": 25,
  "sortModel": [{ "field": "views", "sort": "desc" }],
  "filterModel": {
    "logicOperator": "and",
    "items": [
      { "field": "title",      "operator": "contains", "value": "winter" },
      { "field": "authorName", "operator": "equals",   "value": "Ada" }
    ]
  }
}
```

### 5. The response

```json
{
  "rowCount": 134,
  "rows": [
    { "id": 12, "title": "Winter internals", "views": 9001, "author_name": "Ada" }
  ]
}
```

`rowCount` is the total matching the filter (for the grid's pager); `rows` is the
current page.

---

## Documentation

| Guide | What's inside |
|---|---|
| [Getting started](docs/01-getting-started.md) | End-to-end walkthrough, request/response shape, frontend wiring. |
| [Schema & columns](docs/02-schema.md) | `GridSchema`, `GridColumn`, mapping fields to SQL, whitelisting. |
| [Filtering](docs/03-filtering.md) | `FilterType`, operator gating, per-column overrides, AND/OR logic. |
| [Sorting](docs/04-sorting.md) | Sort model, custom ORDER BY expressions, default order. |
| [Requests & responses](docs/05-requests-and-responses.md) | Extending `MuiGridRequest`, the entity DTOs, `MuiGridResponse`. |
| [Recipes](docs/06-recipes.md) | Joins, subquery search, mappers, category-tree / EXISTS patterns. |
| [Upgrading](docs/07-upgrading.md) | What changed in 3.0 and the two-line migration from 2.x. |

---

## At a glance

```php
// Entry point
MuiGrid::wrap(
    RepositoryViewInterface $repo,     // your pre-built query (SELECT/JOIN/base WHERE)
    MuiGridRequest          $request,  // the grid request DTO
    GridSchema              $schema,   // the filter/sort whitelist  (required)
    ?callable               $mapper = null,  // optional fn(object $row): mixed
): MuiGridResponse;                    // { rowCount, rows }  (JsonSerializable)
```

```php
// Schema building blocks
GridColumn::for('muiField', 'sql.column')
    ->filterable(FilterType::String)         // allow filtering, gate operators by type
    ->sortable()                             // allow sorting by sql.column
    ->sortable('lower(sql.column)')          // ...or by a custom expression
    ->textMatch(TextMatch::Lower)            // per-column LIKE dialect override
    ->filterUsing(fn(MGFilterItem $i): ?Qb => …);  // per-column operator override

GridSchema::make(GridColumn …)
    ->defaultOrder('sql ASC')                // fallback ORDER BY
    ->textMatch(TextMatch::Like);            // LIKE dialect (default: Auto)
```

| `FilterType` | Allowed operators |
|---|---|
| `String` | contains, notContains, startsWith, endsWith, equals, is, not, =, !=, isAnyOf, isEmpty, isNotEmpty |
| `Number` | equals, is, not, =, !=, >, >=, <, <=, isAnyOf, isEmpty, isNotEmpty |
| `Boolean` | is, equals, =, isEmpty, isNotEmpty |
| `Date` | is, not, equals, =, !=, after, onOrAfter, before, onOrBefore, isEmpty, isNotEmpty |

---

## License

MIT — [Flytachi](https://github.com/flytachi)
