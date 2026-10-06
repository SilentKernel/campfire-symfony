# Parity analysis: /opt/campfire-bench/once-campfire-rust-symfony/parity/out/fix1

| seed | cells | pass | flaky | fail | error |
|---|---|---|---|---|---|
| custom_styles | 8 | 0 | 0 | 8 | 0 |
| default | 108 | 0 | 0 | 108 | 0 |
| restricted | 4 | 0 | 0 | 4 | 0 |
| **all** | 120 | 0 | 0 | 120 | 0 |

| layer | compared | equal | different | notes |
|---|---|---|---|---|
| server | 120 | 120 | 0 | explained only by known bugs: 0 |
| live | 120 | 120 | 0 | explained only by known bugs: 0 |
| aria | 120 | 120 | 0 | explained only by known bugs: 0 |
| pixels | 120 | 120 | 0 |  |
| network | 120 | 0 | 120 | asset-only 0, app responses differ 120, explained by proxy headers + known signatures 120 |
| cable | 120 | 120 | 0 | explained only by known bugs: 0 |

## States with differences (beyond asset-only network)

### account/bots (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### account/edit/admin (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### account/edit/with_logo (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### auth/join/invalid_code (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### auth/sign_in/banned_ip (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### auth/transfer/expired (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### errors/404 (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### interactions/boost_created (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/boost_picker (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/edit_saved (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/lightbox (seed default, 8 cells, {'fail': 8})
layers: network(app)×8; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/mention_autocomplete/all (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/mention_autocomplete/empty (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/mention_autocomplete/inserted (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/mention_autocomplete/results (seed default, 8 cells, {'fail': 8})
layers: network(app)×8; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/notifications_help (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### interactions/quick_boost (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### realtime/boosted (seed default, 8 cells, {'fail': 8})
layers: network(app)×8; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### realtime/message_edited (seed default, 8 cells, {'fail': 8})
layers: network(app)×8; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### rooms/opens/new/restricted_member (seed restricted, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### rooms/show/designers (seed default, 14 cells, {'fail': 14})
layers: network(app)×14; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

### rooms/show/designers/custom_styles (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['public-response-header-names']; unexplained network entries: []

