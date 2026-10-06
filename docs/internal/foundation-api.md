# Foundation APIs (agent reports)
Generated from the foundation workflow reports. Authoritative source is the code; this is a map.

## F2 data layer is done, but the kernel does not boot in any environment on the cu

### API

ENTITIES (App\Entity; every one has getId(): int, which throws before the first flush; isNewRecord(): bool; isSameRecord(?object): bool, meaning Active Record's ==)

- Timestamped interface: getCreatedAt().
  - TimestampsTrait gives getCreatedAt(), setCreatedAt(), getUpdatedAt(), setUpdatedAt() and touch($now). The touch only sets the field; the next flush writes it.
  - CreatedAtTrait gives created_at only (Blob, Attachment). VariantRecord has no timestamps.
  - Setters for DateTime fields only assign when the value differs (`!=`), so Doctrine sees no false change.

- Account(name, joinCode)
  - Getters and setters for name, joinCode, customStyles.
  - getSettings(): array, the stored settings merged with SETTINGS_SCHEMA ['restrict_room_creation_to_administrators' => false].
  - getStoredSettings(): ?array, the raw column.
  - assignSettings(array): casts values like ActiveModel::Type::Boolean and throws InvalidArgumentException on an unknown key.
  - restrictsRoomCreationToAdministrators(): bool.
  - A PrePersist hook writes the defaults, like has_json.

- User(name), implements UserInterface and PasswordAuthenticatedUserInterface
  - Fields with getters and setters: emailAddress, passwordDigest (getPassword() returns it), role (UserRole), status (UserStatus), bio, botToken. getMemberships() is EXTRA_LAZY.
  - isMember(), isAdministrator(), isBot(), isActive(), isDeactivated(), isBanned().
  - canAdminister(Room|Message|null $record = null).
  - initials(): Ruby `/\b\w/` semantics, where \w is ASCII and \b is Unicode.
  - title(): "name – bio", with a blank bio dropped.
  - botKey(): "id-token".
  - getUserIdentifier() returns (string) id.
  - getRoles() returns ROLE_USER, plus ROLE_ADMINISTRATOR or ROLE_BOT.
  - Constant MENTION_CONTENT_TYPE.

- Session(User, token, \DateTimeImmutable lastActiveAt, ?userAgent, ?ipAddress)
  - getters, setLastActiveAt(), setUserAgent(), setIpAddress().
  - isActivityStale($now), true when last activity is more than ACTIVITY_REFRESH_RATE = 3600 seconds old.

- abstract Room(User creator, ?name): single-table inheritance on `type`.
  - Subclasses App\Entity\Rooms\{Open,Closed,Direct}, each with a TYPE constant ('Rooms::Open' etc.). Room::TYPES maps class to Rails type.
  - getType(), getName()/setName(), getCreator()/setCreator(), getMemberships().
  - isOpen(), isClosed(), isDirect().
  - getDefaultInvolvement(): Involvement. Mentions; Direct returns Everything.
  - Doctrine never changes the discriminator. To change a room's type, run DBAL `UPDATE rooms SET type`, then refresh.
  - ManyToOne associations pointing at Room are always loaded eagerly. This is a Doctrine STI limitation.

- Membership(Room, User, ?Involvement = Mentions)
  - get/setInvolvement(), isInvolvedIn(Involvement).
  - get/setConnections(), get/setConnectedAt(), get/setUnreadAt().
  - isUnread(); isConnected($now), using CONNECTION_TTL = 60.

- Message(Room, User creator, clientMessageId)
  - getRoom()/setRoom(), getCreator(), get/setClientMessageId().
  - getBoosts(): EXTRA_LAZY and unordered.
  - toKey(): [clientMessageId].
  - RECORD_TYPE = 'Message'.
  - The body is a RichText (record_type 'Message', name 'body'). The attachment is an ActiveStorage Attachment (name 'attachment'). Neither is mapped as a Doctrine association.

- RichText(recordType, recordId, name, ?body): get/setBody(). RECORD_TYPE = 'ActionText::RichText'.
- Boost(Message, User booster, content).
- Search(User, query).
- Ban(User, ipAddress).
- Webhook(User, ?url): get/setUrl().
- PushSubscription(User): get/set for endpoint, p256dhKey, authKey, userAgent.

- ActiveStorage\Blob(key, filename, int byteSize, serviceName = 'local')
  - get/set contentType and checksum.
  - getMetadataJson()/setMetadataJson(?string): the raw text. Encode with ActiveSupport JSON rules before setting.
  - getMetadata(): decoded array.
  - isImage(), isVideo(), isAudio(), isText().
  - getRelativePath(): "xx/yy/key", relative to campfire.files_path.
  - RECORD_TYPE.
- ActiveStorage\Attachment(name, recordType, int recordId, Blob).
- ActiveStorage\VariantRecord(Blob, variationDigest). RECORD_TYPE = 'ActiveStorage::VariantRecord'.

ENUMS (App\Entity\Enum)
- UserRole: int. Member=0, Administrator=1, Bot=2.
- UserStatus: int. Active=0, Deactivated=1, Banned=2.
- Both have railsName() and fromRailsName(string): ?self.
- Involvement: string. Invisible, Nothing, Mentions, Everything.

REPOSITORIES (App\Repository, final ServiceEntityRepository, autowirable)
- AccountRepository::findSingleton(): ?Account.
- UserRepository
  - active(string $alias = 'u'): QueryBuilder.
  - findActive(int): ?User.
  - findOneByEmailAddress(string): ?User. Exact and case-sensitive, like Rails.
  - findActiveByEmailAddress(string): ?User.
  - authenticateBot(string $botKey): ?User.
  - findActiveOrdered(?string $filter = null, bool $withoutBots = false): list<User>. Ordered by LOWER(name); the filter is name LIKE.
  - findActiveBotsOrdered(): list<User>.
- SessionRepository::findOneByToken(string): ?Session. Joins the user.
- RoomRepository
  - findOriginal(): ?Room, the oldest by created_at.
  - findOpenIds(): list<int>.
  - findWithoutDirectsOrdered(): list<Room>.
- MembershipRepository
  - findOneFor(Room|int, User|int): ?Membership.
  - findVisibleWithOrderedRoom(User): list<Membership>.
  - findForRoom(Room): list<Membership>, users joined.
  - findForUser(User): list<Membership>.
  - findConnectedForRoom(Room, \DateTimeImmutable $now).
  - Query builder helpers visible(QueryBuilder, alias = 'm') and withoutDirectRooms(QueryBuilder, alias = 'm').
- MessageRepository::findOneInRoom(Room, int), findOneByClientMessageId(string).
- BoostRepository::findOrderedForMessage(Message); findOrderedByMessageIds(list<int>): array<int messageId, list<Boost>>.
- RichTextRepository::findFor(recordType, recordId, name = 'body'); findForRecords(recordType, list<int>, name = 'body'): array<int recordId, RichText>.
- SearchRepository::findOrderedForUser(User, ?int $limit), ordered by updated_at DESC; findOneForUserByQuery(User, string).
- BanRepository::isBanned(string $ip): bool; findForUser(User).
- WebhookRepository::findOneForUser(User).
- PushSubscriptionRepository::findForUser(User); findOneForUserByKeys(User, ?endpoint, ?p256dh, ?auth).
- ActiveStorage\BlobRepository::findOneByKey(string).
- ActiveStorage\AttachmentRepository::findOneFor(recordType, recordId, name), blob joined; findForRecords(recordType, list<int>, name): array<int recordId, Attachment>.
- ActiveStorage\VariantRecordRepository::findOneFor(Blob, variationDigest).

DATABASE (App\Database)
- TimestampListener: an onFlush Doctrine listener, autoconfigured.
- Schema(Connection, ClockInterface, string $projectDir)
  - isLoaded(): bool.
  - prepare(): bool. True when it loaded the schema; throws PendingMigrations.
  - pendingMigrations(): list<string>, versions(), sql().
  - Constants SCHEMA_SHA1 and ENVIRONMENT.
- PendingMigrations extends \RuntimeException, with public readonly list<string> $versions.
- When binding a datetime query parameter, always pass RailsDateTimeType::NAME as its type.

COMMAND
- `bin/console campfire:install`, class App\Command\InstallCommand, an invokable command. Exit 0 if the database was created or is up to date; exit 1 if migrations are pending.

TEST SUPPORT (App\Tests\Support)
- abstract CampfireTestCase extends WebTestCase
  - Each test gets its own copy of the seed under var/test-storage/<random>/{db/production.sqlite3, files/}, deleted in tearDown. The path is in $this->storagePath.
  - CAMPFIRE_STORAGE_PATH is set in getenv, $_SERVER and $_ENV before the kernel boots.
  - setEnv(string $name, string $value) sets another variable before boot (e.g. CAMPFIRE_FROZEN_TIME) and restores it after the test. It throws if the kernel is already booted.
  - static labels(string $key): int|string, and static id(string $key): int.
  - em(): EntityManagerInterface, and connection(): Doctrine\DBAL\Connection.
- final SeedDatabase (no kernel needed)
  - copy(seed = 'default'): string storagePath.
  - emptyStorage(): string.
  - remove(path).
  - connect(path): DBAL Connection, with SqliteMiddleware and rails_datetime registered.
  - labels(seed): array.
  - seedDir(), projectDir().

### Open issues

- BLOCKER for the lead / Cable agent: in config/services.yaml, `_defaults.bind` has `string $cableSocket` and no service consumes it yet. Symfony refuses to compile the container, so `bin/console` and every functional test fail. `$secretKeyBase` failed the same way earlier but another agent's class consumes it now. Fix by adding the Cable service or removing the binding until then. I verified everything with a temporary consumer class, now deleted.
- config/packages/doctrine.yaml (lead's file): `enable_native_lazy_objects` is deprecated in DoctrineBundle 3.1 (native lazy objects are always on now) and prints a deprecation on every console run. Remove the option.
- config/packages/doctrine.yaml: the schema tool (doctrine:schema:update/validate with sync) fails with 'Unknown database type "json"' when it introspects accounts.settings. Add `mapping_types: { json: json }` under dbal.connections.default if anyone needs schema diffing. The app itself is unaffected.
- No phpstan config (phpstan.dist.neon) exists in the project. My code is clean at level 8. Level max wants phpstan/phpstan-doctrine and phpstan-symfony for typed query results; adding those means a composer.json change, which is for the lead to decide.
- The Transactions service is currently removed from the container because nothing injects it. TransactionsTest constructs it from the app connection. Once feature code injects it, fetching it from the test container will work.
- Possible perf addition (not done, the schema stays exact): the Rust port adds an index, index_messages_on_room_id_and_created_at, at boot. It took room paging from 60 ms to 0.02 ms at 236k messages. Adding it would be a deliberate difference to record in README 'Known differences'.
- Rails `touch: true` (message→room, boost→message) and `insert_all`/`update_all` (memberships) are not in the entities. Domain services should use DBAL with RailsDateTimeType::format($clock->now()) and wrap the calls in Transactions.

## I added the Rails routes and a controller skeleton for each of them, and the rou

### API

CONTROLLERS (namespace App\Controller\…, class name = Rails class, action method = camelCase of Rails action: service_worker -> serviceWorker, health_check -> healthCheck). Endpoint mapping convention: App\Controller\Rooms\OpensController::show <-> "rooms/opens#show" (see tests/Functional/Routing/RailsRouteRecognizer::endpoint).
- abstract App\Controller\ApplicationController extends Symfony AbstractController: empty, F6 owns its body. Every Campfire controller extends it and is final. Skeleton actions are `public function <action>(): Response { throw new HttpException(501, 'Not implemented: <ctrl>#<action>'); }` with no arguments yet. Implementers add arguments such as Request and change the body.
- Framework controllers do not extend ApplicationController. They extend AbstractController and carry #[NotApplicationController]: Rails\HealthController (implemented, constructor(ClockInterface)), Turbo\Native\NavigationController (implemented), ActionMailbox\Ingresses\{Postmark,Relay,Sendgrid,Mandrill,Mailgun}\InboundEmailsController (implemented: empty 404), Rails\Conductor\ActionMailbox\{InboundEmailsController,InboundEmails\SourcesController,ReroutesController,IncineratesController} (implemented: empty 403), ActiveStorage\{Blobs\RedirectController,Blobs\ProxyController,Representations\RedirectController,Representations\ProxyController,DiskController,DirectUploadsController} (501 skeletons for the storage agent).
- Rails inheritance is noted in docblocks only. Rooms\{Opens,Closeds,Directs}Controller subclass RoomsController in Rails: index and destroy are inherited, and so is Directs#show. Messages\ByBotsController subclasses MessagesController (update is inherited). Messages\Boosts\ByBotsController subclasses Messages\BoostsController (destroy is inherited).
- Route params arrive as request attributes named like Rails (id, room_id, message_id, user_id, bot_id, bot_key, join_code, push_subscription_id, signed_id, signed_blob_id, variation_key, encoded_key, encoded_token, filename). The format is `_format`: null when there is no extension, 'json' by default on the bot routes; use $request->getRequestFormat(). user_id defaults to 'me' on the sidebar, profile, push_subscriptions and test_notifications routes. Attributes are not URL-decoded beyond what Symfony does (see open issue 1).

ATTRIBUTES (App\Http\Attribute, all final, no arguments):
- AllowUnauthenticatedAccess (class|method): skip require_authentication. Applied to FirstRunsController, PwaController, QrCodeController and Sessions\TransfersController (class), and to SessionsController::new/create and Accounts\LogosController::show (method).
- RequireUnauthenticatedAccess (class|method): skip require_authentication, then restore_authentication and redirect_signed_in_user_to_root (302 to root). Applied to UsersController::new/create.
- AllowBotAccess (class|method): skip deny_bots. Applied to Messages\ByBotsController::index/create/update/destroy and Messages\Boosts\ByBotsController::create/destroy.
- SkipForgeryProtection (class|method): no CSRF check. Applied to PwaController, ActiveStorage\DiskController and the 5 Action Mailbox ingresses.
- NotApplicationController (class): none of Campfire's ApplicationController filters run (AllowBrowser, Authentication including its CSRF rule, Authorization, BlockBannedRequests, SetCurrentRequest, SetPlatform, TrackedRoomVisit, VersionHeaders). Rails' framework-wide CSRF check (protect_from_forgery with: :exception) still applies unless SkipForgeryProtection is present.
- ActionNotFound (class|method): Rails routes the endpoint but has no such action or controller. Run no filter at all; the action throws NotFoundHttpException (404). On methods it marks 33 actions: first_runs new/edit/update/destroy; sessions edit/show/update; accounts new/show/create/destroy; accounts/users new/create/edit/show; accounts/bots show; users/profiles new/edit/destroy/create; users/push_subscriptions new/edit/show/update; messages new; messages/boosts edit/show/update; rooms new/create/edit/update; rooms/directs update. On a class it marks Rooms\SettingsController.

ROUTE NAMES (name VERB path -> Class::method; every path except / carries the optional ".{_format}" shown in Rails as (.:format)):
root GET / -> WelcomeController::show
new_first_run GET /first_run/new | edit_first_run GET /first_run/edit | first_run GET /first_run | first_run.patch, first_run.put -> update | first_run.delete -> destroy | first_run.post -> create  (FirstRunsController)
session_transfer GET /session/transfers/{id} | session_transfer.patch, session_transfer.put -> update  (Sessions\TransfersController)
new_session GET /session/new | edit_session GET /session/edit | session GET /session | session.patch, session.put -> update | session.delete -> destroy | session.post -> create  (SessionsController)
account_users GET /account/users -> index | account_users.post -> create | new_account_user GET /account/users/new | edit_account_user GET /account/users/{id}/edit | account_user GET /account/users/{id} -> show | account_user.patch, account_user.put -> update | account_user.delete -> destroy  (Accounts\UsersController)
account_bot_key PATCH, account_bot_key.put PUT /account/bots/{bot_id}/key -> Accounts\Bots\KeysController::update
account_bots GET /account/bots | account_bots.post | new_account_bot GET /account/bots/new | edit_account_bot GET /account/bots/{id}/edit | account_bot GET /account/bots/{id} | account_bot.patch, account_bot.put | account_bot.delete  (Accounts\BotsController)
account_join_code POST /account/join_code -> Accounts\JoinCodesController::create
account_logo GET /account/logo -> show | account_logo.delete -> destroy  (Accounts\LogosController)
edit_account_custom_styles GET /account/custom_styles/edit | account_custom_styles PATCH /account/custom_styles | account_custom_styles.put  (Accounts\CustomStylesController)
new_account GET /account/new | edit_account GET /account/edit | account GET /account | account.patch, account.put | account.delete | account.post  (AccountsController)
join GET /join/{join_code} -> UsersController::new | join.post -> UsersController::create | user GET /users/{id} -> UsersController::show
qr_code GET /qr_code/{id} -> QrCodeController::show
user_avatar GET /users/{user_id}/avatar | user_avatar.delete  (Users\AvatarsController)
user_ban DELETE /users/{user_id}/ban -> destroy | user_ban.post -> create  (Users\BansController)
user_sidebar GET /users/{user_id}/sidebar -> Users\SidebarsController::show
new_user_profile GET /users/{user_id}/profile/new | edit_user_profile GET …/profile/edit | user_profile GET /users/{user_id}/profile | user_profile.patch, user_profile.put | user_profile.delete | user_profile.post  (Users\ProfilesController)
user_push_subscription_test_notifications POST /users/{user_id}/push_subscriptions/{push_subscription_id}/test_notifications -> Users\PushSubscriptions\TestNotificationsController::create
user_push_subscriptions GET /users/{user_id}/push_subscriptions | user_push_subscriptions.post | new_user_push_subscription GET …/new | edit_user_push_subscription GET …/{id}/edit | user_push_subscription GET …/{id} | user_push_subscription.patch, user_push_subscription.put | user_push_subscription.delete  (Users\PushSubscriptionsController)
autocompletable_users GET /autocompletable/users -> Autocompletable\UsersController::index
room_messages GET /rooms/{room_id}/messages -> index | room_messages.post -> create | new_room_message GET …/messages/new | edit_room_message GET …/messages/{id}/edit | room_message GET …/messages/{id} -> show | room_message.patch, room_message.put -> update | room_message.delete -> destroy  (MessagesController)
messages GET /messages | messages.post | new_message GET /messages/new | edit_message GET /messages/{id}/edit | message GET /messages/{id} | message.patch, message.put | message.delete  (MessagesController)
room_bot_message_boosts POST /rooms/{room_id}/{bot_key}/messages/{message_id}/boosts -> Messages\Boosts\ByBotsController::create | room_bot_message_boost DELETE …/boosts/{id} -> Messages\Boosts\ByBotsController::destroy
room_bot_messages GET /rooms/{room_id}/{bot_key}/messages -> index | room_bot_messages.post -> create | room_bot_message PATCH, room_bot_message.put PUT …/messages/{id} -> update | room_bot_message.delete -> destroy  (Messages\ByBotsController)
room_refresh GET /rooms/{room_id}/refresh -> Rooms\RefreshesController::show
room_settings GET /rooms/{room_id}/settings -> Rooms\SettingsController::show (404)
room_involvement GET /rooms/{room_id}/involvement | room_involvement.patch, room_involvement.put  (Rooms\InvolvementsController)
room_at_message GET /rooms/{room_id}/@{message_id} -> RoomsController::show
rooms GET /rooms | rooms.post | new_room GET /rooms/new | edit_room GET /rooms/{id}/edit | room GET /rooms/{id} -> show | room.patch, room.put -> update | room.delete -> destroy  (RoomsController)
rooms_opens GET /rooms/opens | rooms_opens.post | new_rooms_open GET …/new | edit_rooms_open GET …/{id}/edit | rooms_open GET /rooms/opens/{id} | rooms_open.patch, rooms_open.put | rooms_open.delete  (Rooms\OpensController); the same pattern gives rooms_closeds/new_rooms_closed/edit_rooms_closed/rooms_closed[.patch|.put|.delete] for Rooms\ClosedsController and rooms_directs/new_rooms_direct/edit_rooms_direct/rooms_direct[.patch|.put|.delete] for Rooms\DirectsController
message_boosts GET /messages/{message_id}/boosts | message_boosts.post | new_message_boost GET …/new | edit_message_boost GET …/{id}/edit | message_boost GET …/{id} | message_boost.patch, message_boost.put | message_boost.delete  (Messages\BoostsController)
clear_searches DELETE /searches/clear -> clear | searches GET /searches -> index | searches.post -> create  (SearchesController)
unfurl_link POST /unfurl_link -> UnfurlLinksController::create
webmanifest GET /webmanifest -> PwaController::manifest | service_worker GET /service-worker -> PwaController::serviceWorker
rails_health_check GET /up -> Rails\HealthController::show
turbo_recede_historical_location, turbo_resume_historical_location, turbo_refresh_historical_location GET /{recede,resume,refresh}_historical_location -> Turbo\Native\NavigationController::{recede,resume,refresh}
rails_{postmark,relay,sendgrid}_inbound_emails POST /rails/action_mailbox/{x}/inbound_emails | rails_mandrill_inbound_health_check GET + rails_mandrill_inbound_emails POST /rails/action_mailbox/mandrill/inbound_emails | rails_mailgun_inbound_emails POST /rails/action_mailbox/mailgun/inbound_emails/mime
rails_conductor_inbound_emails GET (+.post) /rails/conductor/action_mailbox/inbound_emails | new_rails_conductor_inbound_email GET …/new | rails_conductor_inbound_email GET …/{id} | new_rails_conductor_inbound_email_source GET …/inbound_emails/sources/new | rails_conductor_inbound_email_sources POST …/inbound_emails/sources | rails_conductor_inbound_email_reroute POST /rails/conductor/action_mailbox/{inbound_email_id}/reroute | rails_conductor_inbound_email_incinerate POST …/{inbound_email_id}/incinerate
rails_service_blob GET /rails/active_storage/blobs/redirect/{signed_id}/{filename} -> ActiveStorage\Blobs\RedirectController::show | rails_service_blob_proxy GET /rails/active_storage/blobs/proxy/{signed_id}/{filename} -> ActiveStorage\Blobs\ProxyController::show | rails_service_blob_legacy GET /rails/active_storage/blobs/{signed_id}/{filename} -> ActiveStorage\Blobs\RedirectController::show
rails_blob_representation GET /rails/active_storage/representations/redirect/{signed_blob_id}/{variation_key}/{filename} -> ActiveStorage\Representations\RedirectController::show | rails_blob_representation_proxy GET …/representations/proxy/… -> ActiveStorage\Representations\ProxyController::show | rails_blob_representation_legacy GET /rails/active_storage/representations/{signed_blob_id}/{variation_key}/{filename} -> ActiveStorage\Representations\RedirectController::show
rails_disk_service GET /rails/active_storage/disk/{encoded_key}/{filename} -> ActiveStorage\DiskController::show | update_rails_disk_service PUT /rails/active_storage/disk/{encoded_token} -> ActiveStorage\DiskController::update
rails_direct_uploads POST /rails/active_storage/direct_uploads -> ActiveStorage\DirectUploadsController::create
Generation: generate('user_sidebar') gives /users/me/sidebar. generate('room', ['id' => 1, '_format' => 'turbo_stream']) gives /rooms/1.turbo_stream. Bot routes omit .json, as Rails does. filename keeps its slashes (dir/photo.tar).
Test helpers: App\Tests\Functional\Routing\AppRoutes::load(): RouteCollection (loads config/routes.yaml without the kernel); RailsRouteRecognizer(RouteCollection)->recognize(verb, path): ?array{endpoint, params}; RailsRouteRecognizer::normalizePath(string): string; RailsRouteRecognizer::endpoint(string $controller): ?string.

### Open issues

- F6 (request pipeline): Rails' router normalizes the path (squeezes '//', strips a trailing '/', upcases %xx) and matches the still-encoded path, decoding parameters afterwards. Symfony decodes first, which breaks /rooms/a%2Fb, and its RedirectableUrlMatcher would answer /rooms/ and //rooms//1// with a 301 instead of serving them. Please decorate the router's matchRequest/match: normalize with RailsRouteRecognizer::normalizePath, match str_replace('%', '%25', $path), then rawurldecode each non-underscore parameter. The working algorithm is in tests/Functional/Routing/RailsRouteRecognizer.php and can be moved into src/Http.
- F6: Symfony's MethodNotAllowedException (405) must become Rails' 404 (routing error), e.g. PATCH /rooms or GET /searches/clear.
- F6: the filter listeners must honour the attributes: #[ActionNotFound] (method or class) means no filter at all; #[NotApplicationController] means no Campfire ApplicationController filters, but the framework CSRF check still runs unless #[SkipForgeryProtection]; plus AllowUnauthenticatedAccess, RequireUnauthenticatedAccess and AllowBotAccess as their docblocks say. Read attributes on both the method and the declaring class.
- Kernel/container currently fails to compile, outside my area: config/services.yaml binds $cableSocket but no service uses it (also 'messenger.transport.sqs.factory' class missing). That is why the routing tests load routes standalone (AppRoutes). Once fixed, `bin/console debug:router` should list all 177 routes.
- Storage agent: ActiveStorage\Blobs\ProxyController and ActiveStorage\Representations\ProxyController include ActiveStorage::DisableSession in Rails (no session cookie written). There is no attribute for this yet; add one in src/Http/Attribute if you want me to, or handle it in the controller. ActiveStorage\DirectUploadsController::create and DiskController::update also need Campfire's require_active_storage_authentication (reference/config/initializers/active_storage_authentication.rb).
- Naming differs from the task list, chosen to mirror the Rails names and the endpoint convention: ActiveStorage\Blobs\RedirectController (not BlobsRedirectController), ActiveStorage\Representations\{Redirect,Proxy}Controller, Rails\HealthController (not App\Controller\HealthController).
- Rails middleware behaviour (Rack::ETag/ConditionalGet, Vary handling, silence /up logging, the ssl redirect exclusion for /up) is not in the controllers; it belongs to the pipeline or FrankenPHP config.
- HealthController throws NotAcceptableHttpException for unsupported formats. The error-page owner should render it like Rails' 406 for ActionController::UnknownFormat.

## I built F1, the Rails compatibility library, in src/Rails (namespace App\Rails).

### API

Namespace App\Rails. Every class is final and stateless apart from derived keys/verifiers, so it is safe to share across requests in worker mode. "Time" arguments are \DateTimeInterface. When $now is null, the current time is used; callers should pass ClockInterface::now().

KeyGenerator(string $secretKeyBase)
- generateKey(string $salt, int $length = 64): string — raw bytes, PBKDF2-HMAC-SHA256, 1000 iterations, cached per instance. Register it as a service from SECRET_KEY_BASE.

Base64 (static)
- strictEncode(string): string
- strictDecode(string): ?string — as strict as Ruby (padding, trailing bits)
- urlsafeEncode(string, bool $padding = true): string
- urlsafeDecode(string): ?string — lenient like Ruby's urlsafe_decode64

RailsJson (static)
- encode(mixed $value, bool $escapeJsSeparators = false): string — ActiveSupport::JSON.encode. Escapes < > & as < > &; leaves / and unicode unescaped; json-gem floats.
- generate(mixed): string — ::JSON.generate, no HTML escaping.
- decode(string): mixed — assoc arrays; throws \JsonException.
- time(\DateTimeInterface): string — Time#as_json, e.g. "2026-01-01T12:00:00.000Z".
- PHP value mapping: list → JSON array; assoc array / \stdClass → object (use new \stdClass for "{}"); \JsonSerializable; \BackedEnum; \DateTimeInterface; NaN/Inf → null.

RubyFloat (static)
- toS(float): string — Ruby Float#to_s
- toJson(float): string — json gem float format

enum Serializer { Null, Json, JsonWithFallback, JsonAllowMarshal }
- dump(mixed): string
- load(string): mixed — throws InvalidMessage

InvalidMessage extends \RuntimeException
- Codes FORMAT=1, SERIALIZATION=2, CONTENT=3; rotates(): bool

MessageVerifier(string $secret, string $digest = 'sha1', bool $urlSafe = false, Serializer $serializer = Serializer::JsonAllowMarshal, bool $paddedUrlSafe = false)
- generate(mixed $value, ?string $purpose = null, ?\DateTimeInterface $expiresAt = null): string
- verified(?string $signed, ?string $purpose = null, ?\DateTimeInterface $now = null): mixed — null when invalid, expired or wrong purpose. Reads both the "_rails.data" and the legacy "_rails.message" envelopes.
- verify(string $signed, ?string $purpose = null, ?\DateTimeInterface $now = null): mixed — throws InvalidMessage.
- validMessage(string): bool
- withFallback(MessageVerifier): MessageVerifier — Rails rotate: tried only on format/serialization errors.

MessageEncryptor(string $secret32bytes, Serializer $serializer = Serializer::JsonAllowMarshal) — aes-256-gcm
- encryptAndSign(mixed $value, ?string $purpose = null, ?\DateTimeInterface $expiresAt = null): string — format base64(cipher)--base64(iv)--base64(tag)
- decryptAndVerify(?string $message, ?string $purpose = null, ?\DateTimeInterface $now = null): mixed — null on any failure
- decrypt(string): ?string — raw plaintext

RailsCookies(KeyGenerator $keys)
- Constants: SIGNED_COOKIE_SALT = 'signed cookie'; AUTHENTICATED_ENCRYPTED_COOKIE_SALT = 'authenticated encrypted cookie'
- readSigned(string $name, ?string $value, ?\DateTimeInterface $now = null): mixed
- writeSigned(string $name, mixed $value, ?\DateTimeInterface $expiresAt = null): string
- readEncrypted(string $name, ?string $value, ?\DateTimeInterface $now = null): mixed
- writeEncrypted(string $name, mixed $value, ?\DateTimeInterface $expiresAt = null): string
- Values in and out are RAW jar values (unescaped). Values are JSON-dumped with RailsJson::encode, purpose "cookie.<name>". Session hash for _campfire_session: ['session_id' => ..., '_csrf_token' => ..., ...].
- signedVerifier(): MessageVerifier
- encryptor(): MessageEncryptor
- static permanentExpiresAt(\DateTimeInterface $now): \DateTimeImmutable — +20 years
- static escape(string): string
- static unescape(string): string
- static parseCookieHeader(?string $header): array<string,string> — Rack: values unescaped, first occurrence wins
- static setCookieHeader(string $name, string $value, ?\DateTimeInterface $expires, bool $httpOnly, ?string $sameSite, bool $secure, string $path = '/', ?string $domain = null, ?int $maxAge = null): string — Rack order "name=<escaped>; domain; path=/; max-age; expires=Mon, 01 Jan 2046 12:00:00 GMT; secure; httponly; samesite=lax". $value is the RAW value (escaped inside).
- static deleteCookieHeader(string $name, string $path = '/', ?string $domain = null, ?string $sameSite = 'lax'): string

CsrfToken (static)
- generateSecret(): string — the value for session['_csrf_token'], 43 chars
- masked(string $secret): string — csrf meta tag token
- perForm(string $secret, string $normalizedActionPath, string $method): string — masked
- globalToken(string $secret): string — raw bytes
- perFormToken(string $secret, string $actionPath, string $method): string — raw bytes
- mask(string $raw): string
- normalizeActionPath(string $action, string $pagePath): string — $pagePath = request path
- isValid(string $secret, mixed $token, string $path, string $method): bool
- Twig button_to/form_with should use CsrfToken::perForm($secret, CsrfToken::normalizeActionPath($action, $request->getPathInfo()), $method); csrf_meta_tags uses CsrfToken::masked($secret).

ForgeryProtection (static)
- verifiedRequest(string $method, string $path, ?string $origin, string $baseUrl, ?string $secFetchSite, ?string $headerToken, mixed $paramToken, ?string $sessionSecret, bool $originCheck = true): bool — false means Rails would raise InvalidAuthenticityToken (422). $secFetchSite is ignored: Rails 8.2 doesn't use it. $baseUrl = scheme://host[:port] as Rails request.base_url; $paramToken = params['authenticity_token'] (non-string → invalid).
- validRequestOrigin(?string $origin, string $baseUrl): bool — throws InvalidAuthenticityToken for "null"

SignedId(KeyGenerator)
- generate(int $id, string $modelName, ?string $purpose, ?\DateTimeInterface $expiresAt = null): string — $modelName = base class name ('User', 'Room')
- find(string $signed, string $modelName, ?string $purpose, ?\DateTimeInterface $now = null): ?int
- static combinePurposes(string, ?string): string
- static underscore(string): string

AppVerifiers(KeyGenerator)
- verifier(string $name): MessageVerifier — e.g. verifier('ActiveStorage')->generate($blobId, 'blob_id'); blob_key/variation/blob_token use generate($array, $purpose, $expiresAt).

GlobalId (static)
- const APP = 'campfire'
- gid(string $modelName, int|string $id): string
- param(string $gid): string
- parse(string $gidOrParam): ?array{app: string, model: string, id: string}
- fromParam(string): ?array
- parseUri(string): ?array

SignedGlobalId(KeyGenerator)
- generate(string $modelName, int|string $id, string $purpose = 'attachable', ?\DateTimeInterface $expiresAt = null): string — with expiresAt null this is attachable_sgid (data "gid://campfire/X/1?expires_in", no exp).
- sign(string $gidUri, string $purpose = 'default', ?\DateTimeInterface $expiresAt = null): string
- locate(?string $sgid, string $purpose = 'attachable', ?\DateTimeInterface $now = null): ?array{app, model, id}
- static unverifiedLocate(?string $sgid): ?array{app, model, id} — only model 'User'; the caller must check the user exists
- verifier(): MessageVerifier
- Constants SALT, ATTACHABLE_PURPOSE, DEFAULT_PURPOSE

TurboStreamName(KeyGenerator)
- name(list<string> $parts): string — parts already resolved: GlobalId::param(GlobalId::gid('Rooms::Open', 1)), 'messages'
- sign(string $streamName): string
- verify(?string $signed): ?string

Password (static)
- const COST = 12, MIN_COST = 4
- hash(string $password, int $cost = 12): string — "$2a$..."
- verify(string $password, ?string $digest): bool

### Open issues

- Differs from the task spec: RailsJson::encode does NOT escape U+2028/U+2029 by default, because the reference's load_defaults 8.2 sets escape_js_separators_in_json = false (the golden vectors are consistent with this). Callers wanting the old behaviour pass escapeJsSeparators: true.
- Rails 8.2 forgery protection (the actionpack in the image) has no Sec-Fetch-Site check. ForgeryProtection::verifiedRequest accepts the header and ignores it. App\Http\Csrf should pass Rails' request.base_url (scheme://host[:port]) and the request path without query, return 422 when the result is false, and skip verification for bot-key requests (protect_from_forgery ... unless: bot_key?). When the session has no _csrf_token, Rails generates one on first use (CsrfToken::generateSecret) and stores it in the session.
- SignedGlobalId::unverifiedLocate returns null where Rails' attachable_from_possibly_expired_sgid would raise (bad JSON, bad Base64, a JSON array). This should go in README 'Known differences' (README is not mine). It only returns User GIDs; the RichText owner must still confirm the user exists.
- GlobalId::parse supports single-column ids only; composite gid ids return null. Campfire has no composite keys.
- Ruby String#to_i / ActiveModel integer casting (vectors ruby_core.json strings[].to_i / integer_cast, including 0d prefixes, underscores and RangeError) is not implemented here. SignedId::find uses a simple leading-integer cast. Whoever owns params casting should port it against those vectors; I can add it to App\Rails if wanted.
- Defaults: verified()/locate()/decryptAndVerify() fall back to new \DateTimeImmutable() when $now is null. Per AGENTS.md, callers should pass ClockInterface::now() so CAMPFIRE_FROZEN_TIME applies.
- Services: KeyGenerator needs the Rails SECRET_KEY_BASE wired as its constructor argument (config/services.yaml, not mine). RailsCookies, SignedId, AppVerifiers, SignedGlobalId and TurboStreamName autowire from KeyGenerator.

## F4 is finished. Its tests pass, but only when run through a scratch kernel: the 

### API

FILE NAMES: AssetMapper file names are Rails' own. The layout calls image_tag('check.svg'), asset_path('bell.mp3'), image_url('screenshots/android-chat.png').

TWIG LAYOUT (templates/layouts/application.html.twig)
- Child templates do {% extends 'layouts/application.html.twig' %}.
- Blocks: head (content_for :head), nav, content (the view's yield), footer, sidebar.
- Set the title and body class at top level, outside blocks: {% set page_title = 'Sign in' %} and {% set body_class = 'sidebar' %} (Rails @page_title/@body_class).
- turbo_page_requires_reload(), turbo_exempts_page_from_preview() etc. return the meta tag; put them inside {% block head %}.
- Partials: layouts/_lightbox.html.twig; layouts/action_text/contents/_content.html.twig (expects variable `content` as Markup).
- Whitespace: a block tag at the end of a line eats its newline; mirror ERB trim with {%~ ... %}.

ESCAPING: auto-escape and |e / |escape('html'|'html_attr') produce ERB output (' becomes &#39;).
- PHP: App\Twig\Escaper\ErbEscaper::html(string): string and ::escapeValue(mixed): string. Twig\Markup counts as html_safe.

ASSET FUNCTIONS (App\Twig\AssetExtension)
- asset_path / image_path / audio_path(logical): string. URLs and absolute paths pass through unchanged.
- asset_url / image_url(logical): absolute URL on the current request host.
- image_tag(source, {options}): user attributes first, then src, then width/height from size ('24' or '20x30'); legacy ' />' form.
- stylesheet_link_tag('all', {'data-turbo-track': 'reload'}), alias stylesheet_link_tags.
- javascript_importmap_tags(entry = 'application').
- PHP: App\Twig\Asset\Assets: path(string): string, stylesheetLinkTags(list $sources, array $options): string, javascriptImportmapTags(string $entry = 'application'): string, allStylesheets(): list.

TAG FUNCTIONS (App\Twig\TagExtension). Options are Twig hashes; attribute order is preserved like a Ruby hash. data/aria sub-hashes become dasherized attributes (non-string data values are JSON via RailsJson). Rails BOOLEAN_ATTRIBUTES render as name="name". null drops the attribute.
- content_tag(name, content, {opts}): content escaped unless Markup.
- html_tag(name, {opts}, content = null): Rails `tag.name`; void elements render without a slash (`<meta ...>`).
- tag(name, {opts}, open = false): legacy form, `<x ... />`.
- tag_attributes({...}), token_list(...), class_names(...), safe_join(list, sep).
- dom_id(record_or_class, prefix = null) and dom_class(record_or_class, prefix = null).
- link_to(name, url, {opts incl. method}).
- button_to(content, url, {method, form, form_class, params, authenticity_token, ...html}) produces the form.button_to with the _method field and the per-form token.
- form_with({url, method, id, class, data, multipart, html, authenticity_token}) returns the opening `<form ...>` plus the _method and authenticity_token hidden fields; close it with </form>.
- auto_submit_form_with({...}): same, with data-controller 'auto-submit ...'.
- token_tag(action, method = 'post'), method_tag(method), csrf_meta_tags(), csp_meta_tag() (returns ''), drop_target_actions().

TURBO FUNCTIONS (App\Twig\TurboExtension)
- turbo_frame_tag(id | record | [record, prefix] | [parts], {attrs incl. src/target}, contentHtml).
- turbo_stream_from(...streamables, {channel: 'X'}): trailing hash = attributes. Renders `<turbo-cable-stream-source channel=... signed-stream-name=...></turbo-cable-stream-source>`.
- turbo_stream(action, target, contentHtml, {method: 'morph', ...}), turbo_stream_all(action, targets, html, attrs), turbo_stream_action_tag(action, {target, targets, template, ...}), turbo_stream_refresh_tag(requestId).
- Drive helpers: turbo_exempts_page_from_cache(_tag), turbo_exempts_page_from_preview(_tag), turbo_page_requires_reload(_tag), turbo_refresh_method_tag(m), turbo_refresh_scroll_tag(s), turbo_refreshes_with(m, s).
- PHP builders for controllers and broadcasts (App\Twig\Html\TurboStream): actionTag(string $action, mixed $target = null, mixed $targets = null, string|Markup|null $template = null, array $attributes = []): string; action(action, target, content, ?method); actionAll(...); refresh(?requestId); frameTag(ids, attrs, content); streamNameParts(array $streamables): list<string>. streamNameParts turns records into GlobalId to_gid_param with the STI class name; join the result with ':' for TurboStreamName::name.

RECORD IDENTIFIER (PHP): App\Twig\Html\RecordIdentifier::domId(object|class-string, ?prefix), domClass, paramKey, modelName.
- STI subclasses keep their own name ('Rooms::Open' gives rooms_open). Engine models use relative names (rich_text, attachment, blob). PushSubscription maps to push_subscription.
- The key comes from toKey() when the entity has it (Message uses client_message_id), otherwise the id; a new record gives 'new_x'.

APPLICATION FUNCTIONS (App\Twig\ApplicationExtension)
- page_title_tag(), body_classes(): read page_title / body_class from the template context.
- current_user_meta_tags(), custom_styles_tag(), script_aware_action_cable_meta_tag() (always content '/cable'), vapid_public_key_meta_tag() (from %env(VAPID_PUBLIC_KEY)%; '' omits content).
- link_back(), link_back_to(url), version_badge(), app_version() (APP_VERSION, then GIT_REVISION, then '0').
- current_user(), current_account(), flash(key): ?string.
- platform(): ?object. Reads request attribute 'campfire.platform'; null when absent. Use as platform().ios() etc.

AVATAR FUNCTIONS (App\Twig\AvatarsExtension)
- avatar_tag(user, {img opts}), avatar_background_color(user), fresh_user_avatar_path(user), fresh_user_avatar_url(user), fresh_account_logo_path(size = null), account_logo_tag(style = null).

OTHER HELPERS (App\Twig\HelpersExtension)
- button_to_copy_to_clipboard(url, contentHtml), emoji_reactions(): array (HelpersExtension::REACTIONS), local_datetime_tag(datetime, style = 'time', {attrs}) (UTC ISO8601 ending in 'Z'), translations_for(key), translation_button(key), link_to_zoom_qr_code(url, contentHtml), broadcast_image_path / broadcast_image_tag (string sources only).
- App\Twig\Translations::TRANSLATIONS holds the translation strings.

PHP HELPERS: App\Twig\View\ViewHelpers: imageTag(string $source, array $options = []), linkTo(mixed $name, string $url, array $options = []), buttonTo(mixed $content, ?string $url, array $options = []), formWith(array $options = []), tokenTag(string|bool|null $token, string $action, string $method = 'post'), methodTag(string), csrfMetaTags(), static extractDimensions(string), static h(mixed).
- App\Twig\Html\Tag: content($name, $content, $options, $escape), void($name, $options), legacy($name, $options, $open), options(array), buildValues(...), tokenList(...), rubyToS(mixed).

REQUEST STATE: interface App\Twig\View\ViewContext (user, account, request, accountHasLogo, flash, csrfParam, csrfToken, formCsrfToken(action, method), signedStreamName(array), avatarToken(User), platform).
- Implemented by App\Twig\View\RequestViewContext (resettable). It uses Current, Csrf, Flash, TurboStreamName, SignedId (generate(id, 'User', 'avatar')) and a DBAL query for the account logo attachment.

ROUTES the helpers generate (Rails names and parameter names):
- root
- user {id}
- user_avatar {user_id: avatar_token, v: YmdHis}
- account_logo {v, size}
- qr_code {id: urlsafe base64}
- webmanifest with {_format: 'json'}; the layout expects '/webmanifest.json'.

ASSET COMPILER: App\Asset\PropshaftCssAssetUrlCompiler decorates asset_mapper.compiler.css_asset_url_compiler.

### Open issues

- The container does not compile right now for two reasons outside F4. (1) config/services.yaml has a `string $cableSocket` binding in _defaults that no service uses yet, so ResolveBindingsPass fails; the cable workstream needs to add its consumer or remove the binding. (2) App\Http\Csrf (maskedToken/formToken/param) and App\Http\Flash (get) don't exist yet (F6); App\Http\Current now exists and matches the spec. Until both are fixed, tests/Functional/Assets can only run via a kernel that works around them.
- Routes owner: the layout calls path('webmanifest', {_format: 'json'}) and expects '/webmanifest.json', so the route should be '/webmanifest.{_format}'. The helpers also generate root, user {id}, user_avatar {user_id, v}, account_logo {v, size} and qr_code {id}: keep those parameter names.
- F6 / CSRF: button_to and form_with call Csrf::formToken(action, method) with the action exactly as rendered, which can be a full URL (Rails sign-in form: action="http://host/session"). Rails normalizes this to the path before computing per-form tokens; Csrf::formToken must do the same.
- Platform workstream: set request attribute 'campfire.platform' to the ApplicationPlatform object; templates call platform().ios() etc., and platform() returns null when the attribute is missing.
- README 'Known differences' (not an F4 file), please record: asset digests are AssetMapper's 7-character format, not Propshaft's 8 hex characters; source-map comments in lexxy.js, stimulus.min.js and turbo.min.js are relative URLs instead of absolute ones (same map file); public/ has no 400.html or 406-unsupported-browser.html because the reference app has none.
- The Rails ERB view ports need to know how blocks map to Twig: anything Rails passes as a block (button_to, link_to, turbo_frame_tag, button_to_copy_to_clipboard, link_to_zoom_qr_code) takes its HTML as an argument in Twig, built with {% set x %}...{% endset %}; form_with returns only the opening tag plus hidden fields, so templates write the fields and </form> themselves. Form field builders (text_field, check_box, ...) are not in F4.
- Minor overlap: App\Twig\Html\Inflector::underscore duplicates App\Rails\SignedId::underscore; either can be consolidated later.

## F5 is finished, but the real production image does not build yet. The build fail

### API

CONTAINER CONTRACT
- Image: `docker build .` builds the `prod` stage (default). `docker build --target dev` builds the toolchain image (dev vendor/, tests, memory_limit=-1).
- WORKDIR /rails. Default USER 1000:1000; any --user uid:gid works because /rails/var and /rails/storage are mode a+rwX.
- CMD ["bin/start"]. ENTRYPOINT is the base image's docker-php-entrypoint.
- Hooks are /hooks/pre-backup and /hooks/post-restore.
- ENV set in the image: APP_ENV=prod, CAMPFIRE_STORAGE_PATH=/rails/storage, XDG_CONFIG_HOME/XDG_DATA_HOME under /tmp/caddy, APP_VERSION and GIT_REVISION from build args.
- Build time: `composer dump-env prod` produces .env.local.php (real env vars still win), then `asset-map:compile` and `cache:warmup` run with SECRET_KEY_BASE=build-only-dummy-secret. Both must succeed or the image does not build.

bin/start ENVIRONMENT
- SECRET_KEY_BASE is required.
- HTTP_PORT (default 80), HTTPS_PORT (default 443), TARGET_PORT (default HTTP_PORT+1).
- TLS_DOMAIN: comma-separated domains get automatic HTTPS, unless DISABLE_SSL is set. ACME_DIRECTORY is honoured.
- PHP_WORKERS (default 2×nproc), PHP_THREADS (default 1; FrankenPHP adds the worker threads to it), JOB_CONCURRENCY (default 1), CADDY_LOG_LEVEL (default WARN).

bin/start STARTUP SEQUENCE
1. Exports CAMPFIRE_STORAGE_PATH and runs `mkdir -p storage/db storage/files var/run`.
2. If `bin/console list --raw` shows `campfire:install`, runs `php bin/console campfire:install --no-interaction` in the foreground, before anything else.
3. If `campfire:cable` exists, runs `php bin/console campfire:cable` in the background and restarts it whenever it exits.
4. Runs JOB_CONCURRENCY × `php bin/console messenger:consume async --sleep=0.1 --time-limit=3600 --memory-limit=256M --no-interaction`, each restarted when it exits.
5. Runs `frankenphp run --config docker/Caddyfile --adapter caddyfile`.
- TERM/INT: TERM is sent to every child, then bin/start exits 0.
- If frankenphp dies: everything is stopped and bin/start exits with frankenphp's status (1 if that status was 0).

CADDYFILE ROUTING (docker/Caddyfile)
- `/cable` is reverse-proxied to 127.0.0.1:$TARGET_PORT. The cable server must listen there in plain HTTP/WebSocket.
- Existing files under /rails/public/assets/* get `Cache-Control: public, immutable, max-age=31536000`.
- Other existing files in public/ (not *.php, not directories) get `Cache-Control: public, max-age=2592000`.
- Everything else goes to the FrankenPHP worker at /rails/public/index.php.
- `encode gzip 6` with minimum_length 512. Request bodies are capped at 100MB. Admin API off, Server header removed, no access logs.

X-SENDFILE OFFLOAD (for the Active Storage owner)
- Caddy sends every PHP request with `X-Sendfile-Type: x-accel-redirect` and `X-Accel-Mapping: {CAMPFIRE_STORAGE_PATH}/files/=/`.
- In the blob/disk controller, call `\Symfony\Component\HttpFoundation\BinaryFileResponse::trustXSendfileTypeHeader()` (a static flag that stays set for the worker's lifetime), then return `new BinaryFileResponse($absolutePathUnderStorageFiles, 200, [...headers])`.
- Symfony then sends `X-Accel-Redirect: /xx/yy/key` with an empty body, and Caddy serves the file from storage/files.
- The app's own Content-Type, Content-Disposition and Cache-Control are kept; Caddy adds ETag and Last-Modified and handles Range and HEAD.
- Only paths under storage/files can be offloaded. Anything else must be streamed normally.

php.ini (docker/php.ini → conf.d/zz-campfire.ini)
- UTC, memory_limit 512M, upload_max_filesize 100M, post_max_size 105M, errors to stderr, realpath_cache_size 4096K.
- opcache enabled for FrankenPHP only: enable_cli=0, validate_timestamps=0, memory_consumption 256, interned_strings_buffer 32, max_accelerated_files 32531, preload=/rails/config/preload.php, preload_user=www-data.
- APCu: apc.enable_cli=1, shm_size 128M. FFI: ffi.enable=true. expose_php=Off.

HOOKS
- pre-backup: `sqlite3 .backup` of storage/db/{CAMPFIRE_DATABASE_NAME:-production}.sqlite3 and jobs.sqlite3 into storage/backups/ (temp file, then mv).
- post-restore: copies the snapshots back into storage/db and deletes -wal and -shm.

bin/check
- `bin/check [phpunit|phpstan|cs] [args]` builds the dev stage as campfire-symfony:dev and runs inside it, with var/seed mounted read-only at /rails/var/seed.
- Default runs all three: `php bin/phpunit`, `vendor/bin/phpstan analyse` (phpstan.dist.neon: level 6, paths: src) and `vendor/bin/php-cs-fixer fix --dry-run --diff`.

SMOKE TEST
- `tests/Container/smoke.sh [--no-build]`. Env: SMOKE_IMAGE (default campfire-symfony:smoke), SMOKE_SEED, SMOKE_USER (default 12345:12345).

### Open issues

- The prod image cannot build until `cache:warmup` succeeds under APP_ENV=prod. Right now config/services.yaml binds `string $cableSocket` but no service uses it, which fails ResolveBindingsPass. The config/services owner (or the agent writing App\Cable\Broadcaster) needs to add a consumer or remove the binding. bin/check's PHPUnit run fails on the same error (259 errors).
- The build also runs `asset-map:compile`, so importmap.php must point only at assets that exist. An earlier version pointed at a missing `./assets/app.js`; it has since been rewritten. Assets that are missing make the image build fail.
- campfire:cable (Cable owner): bin/start runs `php bin/console campfire:cable` with no arguments, in the foreground, and expects it to listen on 127.0.0.1:$TARGET_PORT. Workerman's pidFile and logFile default to locations under vendor/, which are read-only in the image, so set them under var/run (writable) or use stdout. Workerman looks at $argv for start/stop, so the command must set the global argv itself (e.g. to `start`). The `event` extension is installed and verified on ZTS, so `Worker::$eventLoopClass = Workerman\\Events\\Event::class` works. The command should exit on SIGTERM.
- campfire:install (F2) runs as whatever uid the container uses, before the web server starts. It must create storage/db/production.sqlite3 when missing and do nothing otherwise. It must not write anywhere outside var/ and storage/.
- src/Database/SqliteMiddleware.php line 50: an anonymous class extends AbstractConnectionMiddleware. Each time FrankenPHP starts, preload logs 'Can't preload unlinked class ...@anonymous' (harmless). A named final class would remove the warning.
- Known difference for README (I don't own it): reference/config/environments/production.rb sets public_file_server.headers twice, and the second one wins: 'public, max-age=2592000' for every file, /assets/ included. As the task asks, the Caddyfile instead sends 'public, immutable, max-age=31536000' for /assets/* and 30 days for everything else in public/.
- public/ contains only index.php for now. Rails' public/ files (robots.txt, 404.html, icons and so on) are served by the @public rule as soon as the frontend or views owner copies them in.
- README (not mine) should document the container env vars, the `docker build --target dev` stage, bin/check, and tests/Container/smoke.sh.

## F6 is done: the request pipeline and Security are built. `php bin/phpunit` passe

### API

App\Http (every per-request service implements ResetInterface and is reset between worker requests)

- Current
  - Reads: user(): ?User; session(): ?Session; request(): ?Request (set only by the ApplicationController chain); account(): ?Account (Account.first, memoized).
  - authenticatedBy(): '' | 'session' | 'bot_key' (constants BY_SESSION, BY_BOT_KEY); isAuthenticatedByBotKey(); isSignedIn().
  - Setters: setUser(?User); setSession(?Session), which also sets the user; setRequest(?Request); setAuthenticatedBy(string).
- RailsSession (cookie _campfire_session, KEY constant)
  - get(string): mixed; has(string): bool; set(string, mixed): void; delete(string): mixed (returns the removed value); all(): array; id(): string.
  - resetSession(): Rails `reset_session` (new id, empty data). Note: reset() is the worker reset, not Rails' reset.
  - isLoaded(); generation(); commit(), called by the pipeline; static generateSid().
- Flash
  - get(key); has(key); set(key, value) for the next request; now(key, value) for this request only.
  - keep(?key); discard(?key); delete(key); all(); notice(); alert().
  - commit(), called by the pipeline; toSessionValue().
- Csrf (readonly)
  - param() returns 'authenticity_token' (constant PARAM).
  - maskedToken(): the csrf meta tag token.
  - formToken(string $action, string $method): per-form token, action normalized against the request path.
  - verify(Request): bool, Rails verified_request?.
  - secret(): reads session['_csrf_token'], generating and storing it on first use.
- Params (readonly)
  - static fromRequest(Request): Params, memoized on the request. Merge order: body, then query, then path attributes; `_format` becomes 'format'.
  - get(string $keyOrDotPath, $default = null); has(key); string(path): ?string.
  - fetch(key, ...$default): a missing key with no default throws ParameterMissing.
  - require(key): returns Params for a hash, or the scalar; a blank value throws ParameterMissing (400).
  - permit(...filters) with 'k', ['k' => []] or ['k' => ['a']], returning an array; expect(key, filters): array; all().
  - static parseQuery(string): Rails ParamBuilder parsing; static isBlank(mixed).
- Cookies (Rails cookie jar)
  - has, get (raw), signed(name), encrypted(name).
  - set(name, string, options); setSigned(name, value, options); setEncrypted(...); delete(name, options).
  - Options: permanent (bool, +20y), expires, httponly, same_site (default 'lax'), path, domain, secure.
  - responseCookies(): list<RawCookie>.
- Mime
  - TYPES; ALL = '*/*'.
  - static formats(Request): list<string> of symbols ('html', 'turbo_stream', 'json', '*/*').
  - static format(Request); negotiate(Request, list<string> $offered): ?string; respondTo(Request, offered): string, throws UnknownFormat (406).
  - static shouldApplyVaryHeader(Request); typeOf(symbol); lookup(type); lookupByExtension(ext); parseAccept(string).
- Halt(Response) exception: the response is sent as a normal one.
- Exceptions (App\Http\Exception): ParameterMissing and InvalidParameter (400), RecordNotFound (404; RecordNotFound::for('Room', $id)), UnknownFormat (406), UnsafeRedirect (500). App\Rails\InvalidAuthenticityToken gives 422. Doctrine EntityNotFound and NoResult give 404.
- ErrorPages
  - render(int $status, Request): Response.
  - static statusFor(Throwable): int.
- BrowserPolicy interface: check(Request): ?Response. The default, AllowAllBrowserPolicy, is registered with #[AsAlias(BrowserPolicy::class)].
- Ssl (readonly)
  - `enabled` is true unless DISABLE_SSL is set.
  - HSTS constant.
- Pipeline constants (request attributes): HALTED, EXCEPTION, VERSION_HEADERS, CACHE_CONTROL, CONTENT_TYPE; static isHalted(Request), isException(Request).
- Listeners
  - AssumeSslListener: kernel.request, priority 2048.
  - FilterChainListener: kernel.controller, priority -8. A filter that responds replaces the controller and sets HALTED.
  - ShowExceptionsListener: kernel.exception, priority -64.
  - RackResponseListener: kernel.response at priority 8 (ETag/304) and at -2048 (version headers, flash, session, cookies, SSL, header bag swap).

App\Security
- Authentication (readonly). Constants: COOKIE = 'session_token', RETURN_TO = 'return_to_after_authenticating', MODE_ATTRIBUTE, MODE_RESTORE, MODE_REQUIRE.
  - requireAuthentication(Request): ?Response, null when signed in, else the 302 to new_session_url with return_to stored.
  - restoreAuthentication(Request): bool; requestAuthentication(Request): Response; redirectSignedInUserToRoot(): ?Response.
  - findSessionByCookie(): ?Session; resumeSession(Session, Request); authenticatedAs(Session); authenticatedAsBot(User).
  - startNewSessionFor(User, ?Request = null): Session. Creates the row with a has_secure_token base58(24) token and sets the permanent signed httponly lax cookie.
  - terminateCurrentSession(): destroys the row, calls resetSession, deletes the cookie, then Broadcaster->disconnectUser(id, reconnect: true); errors there are only logged.
  - postAuthenticatingUrl(): string; static mode(Request).
- SessionCookieAuthenticator and BotKeyAuthenticator: custom authenticators on the lazy "main" firewall.
- UserProvider: users by id.
- SecureToken::generate(int $length = 24).

App\Cable
- Broadcaster interface: broadcast(string $stream, string|array $payload): void; disconnectUser(int $userId, bool $reconnect = false): void.
- NullBroadcaster: the alias in dev and prod.
- RecordingBroadcaster: the alias in test, public. Public arrays $broadcasts and $disconnects; clear().

App\Controller\ApplicationController (abstract, service subscriber). Protected helpers:
- Accessors: current(): Current; currentUser(): ?User; signedIn(): bool; flash(): Flash; session(): RailsSession; csrf(): Csrf; cookies(): Cookies; params(Request): Params.
- head(int $status, array $headers = [], ?Request = null): typed as the request format, no charset.
- turboStream(string $html, int $status = 200): 'text/vnd.turbo-stream.html; charset=utf-8'.
- html(string, int = 200); render() is overridden to set 'text/html; charset=utf-8'.
- redirectTo(string $url, int $status = 302, bool $allowOtherHost = false, ?string $notice = null, ?string $alert = null): empty body; a path gains the request host; another host or a path-relative URL throws UnsafeRedirect.
- redirectBackOr(string $fallback, int $status = 302, ?notice, ?alert).
- respondTo(Request, array<format, callable(): Response>): format keys in Rails order, with Mime::ALL meaning `any`; adds Vary: Accept when the Accept header decided.
- requireAdministrator(): halts with 403; halt(Response): never.
- startNewSessionFor(User): Session; terminateCurrentSession(); postAuthenticatingUrl(): string.

Twig: F4's RequestViewContext already provides current_user, flash, csrf_meta_tags and form tokens through Current, Flash and Csrf, so there is no src/Twig/HttpExtension.php.

### Open issues

- I removed `string $cableSocket` from `_defaults.bind` in config/services.yaml (my file). It was the blocker F2 reported: nothing consumed it, so the container would not compile. The parameter `campfire.cable_socket` is still there. The Cable agent should inject it with #[Autowire('%campfire.cable_socket%')] or add the binding back once a class takes it.
- App\Cable\Broadcaster did not exist, so I created it, with NullBroadcaster (alias in dev/prod via #[AsAlias] + #[When]) and RecordingBroadcaster (alias in test). The Cable agent owns src/Cable. When it adds the real publisher, it should take the AsAlias attribute off NullBroadcaster, or delete NullBroadcaster, and put the alias on its own class. Two aliases for the same interface would conflict.
- BrowserPolicy works the same way: AllowAllBrowserPolicy carries #[AsAlias(BrowserPolicy::class)]. The workstream implementing allow_browser must remove that attribute (or delete the class) and alias its own policy. Its check() returns null to let the request through, or a Response (the incompatible-browser page, status 200) to halt.
- public/ only contains index.php. Rails' error pages (reference/public/404.html, 422.html, 500.html, 502.html) need copying into public/ by whoever owns it. Until then ErrorPages falls back to reading reference/public/<status>.html, which may not exist in the production image.
- Controllers that F3 stubbed with HttpException(501) produce error pages, which correctly carry no X-Version and no cookies, as in Rails. My functional tests use a test-only kernel.controller_arguments listener (HttpTestCase::respondAfterFilters) to produce real responses after the filters. Other agents can reuse it.
- Not ported: Rails' JSON wrap_parameters, where JSON bodies are also nested under the controller's model key. Controllers that take JSON should read the top-level keys directly, or the owner of those controllers should add the wrapping if a Rails client relies on it.
- Not ported: Rails' verify_same_origin_request, the 422 for cross-origin non-XHR GETs that return JavaScript. The only JavaScript endpoint, PwaController's service worker, skips forgery protection anyway.
- X-Rev: Rails drops the header when GIT_REVISION is unset. Our %env() default cannot tell unset from empty, so an empty GIT_REVISION also omits the header, while Rails would send it with an empty value.
- Symfony adds a Date header to every response; Rails/Puma does not. Response::prepare would also append '; charset=UTF-8' to text/* types that have no charset, so RackResponseListener restores the controller's original Content-Type (used by Rails' `head`). Responses with no Content-Type at all still get Symfony's default 'text/html; charset=UTF-8', so controllers should set content types explicitly (the ApplicationController helpers do).
- Rails sets X-Version through the controller, and Rails' 304 responses keep it; ours do too. The Rust port, by contrast, only rewrites cookies when the session changed. We follow Rails, so `_campfire_session` and `session_token` are re-sent on authenticated responses, as the reference does.
- For README 'Known differences' (README is not mine), two items: (1) force_ssl never redirects http to https, because assume_ssl makes every request HTTPS; that is the reference's behaviour too, so this port has no redirect and no /up exclusion. (2) The firewall is lazy rather than `stateless`, because Symfony cannot make a stateless firewall lazy. Behaviour is unaffected, since framework.session is off.

## The foundation is integrated and green. When I picked it up, the suite (`php bin

### API

New since the agent reports:
- App\Http\EventListener\RailsRoutingListener: kernel.request, priority 33.
  - Routes the request Journey-style and sets `_controller`, `_route`, `_route_params` and the decoded params, so Symfony's RouterListener only sets the context.
  - public static normalizePath(string $path): string, which is actionpack's normalize_path.
  - An unknown path and a path that exists only for another verb both throw NotFoundHttpException (404).
- App\Http\EventListener\RackResponseListener::DEFAULT_HEADERS: Rails' action_dispatch.default_headers, added to non-exception responses unless the controller set them.
  - X-Request-Id (sanitized incoming value or a UUID v4) and X-Runtime ("%0.6f") are on every main response, error pages included.
- App\Database\SqliteDriver (extends AbstractDriverMiddleware) and App\Database\SqliteConnection (extends AbstractConnectionMiddleware) replace the anonymous classes. SqliteMiddleware::wrap() returns new SqliteDriver($driver); SqliteMiddleware::PRAGMAS is unchanged.
- tests/Container/smoke.sh:
  - Containers now run with CADDY_LOG_LEVEL=INFO.
  - New checks: worker_threads, no PHP warnings, and the session checks when the seed's labels.json exists (rooms.watercooler, sessions.david_safari).
  - Unchanged: SMOKE_IMAGE, SMOKE_SEED, SMOKE_USER and --no-build.
- README.md exists now; add deliberate differences under "Known differences".

### Open issues

- Nothing in Symfony implements the X-Version requirement from the task for a 501 stub, and nothing should: X-Version is set by a before_action, and an exception (the 501) is rendered as an error page without it, as in Rails. Real actions will carry it.
- campfire:cable doesn't exist yet: bin/start disables Action Cable and smoke.sh skips its check. Cable owner: add the real Broadcaster and move the #[AsAlias] off NullBroadcaster, inject %campfire.cable_socket%, set Workerman's pid and log files under var/run, and set argv to 'start' (see F5's notes).
- Not reproduced, because they come from the Thruster proxy or Puma: X-Cache, and the Rails 422 reason phrase 'Unprocessable Content' in the status line (Go writes 'Unprocessable Entity'; the JSON error body already says 'Unprocessable Content'). Symfony adds a Date header. All of this is listed in README Known differences.
- The test container cache once missed the newly added listener file (var/cache/test was stale until I deleted it). If a newly added src class is not picked up, run `rm -rf var/cache/test`.
- Still not ported (from F1): Ruby String#to_i / ActiveModel integer casting against ruby_core.json, for whoever owns params casting.
- Optional performance index (index_messages_on_room_id_and_created_at, as the Rust port adds) is not added. The schema stays exactly Rails'.
- A container named campfire-f4-ref (campfire-reference:app on 127.0.0.1:3197) was already running from F4. I left it alone because I didn't start it. Remove it if no one needs it.
- Cross-compatibility used a scratch script outside the repo (it ran Authentication::startNewSessionFor through kernel->handle). I added no dev command to src/. If parity tests need to repeat this, they could add a dev-only command or test helper.
