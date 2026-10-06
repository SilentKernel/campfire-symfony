# Generates tests/fixtures/richtext/seed.json: for every message body in the default seed
# (var/seed/default), what the Rails app renders, as reference-tools/richtext/generate.rb does for
# the corpus. Run with tests/fixtures/richtext/run_seed.sh.
require "json"

OUTPUT = ENV.fetch("OUTPUT", "/out/seed.json")
HOST = "once.campfire.test"

Rails.logger = Logger.new(nil)
ActiveRecord::Base.logger = nil

def outcome
  { "ok" => yield }
rescue Exception => e
  { "error" => e.class.name, "message" => e.message.to_s.scrub[0, 300] }
end

request = ActionDispatch::Request.new(Rack::MockRequest.env_for("http://#{HOST}/"))
controller = MessagesController.new
controller.set_request!(request)
controller.set_response!(ActionDispatch::Response.new)

cases = Current.set(request: request) do
  ActionText::Content.with_renderer(controller) do
    v = controller.view_context
    Message.order(:id).map do |message|
      {
        "message_id" => message.id,
        "body" => message.body&.body_before_type_cast,
        "content_type" => message.content_type.to_s,
        "presentation" => outcome { message.content_type.text? ? v.message_presentation(message).to_s : nil },
        "plain_text" => outcome { message.body.to_plain_text },
        "editable" => outcome { message.body.body ? v.send(:render_custom_attachments_in, v.editable_body(message))&.to_s : nil },
        "mentioned" => outcome { message.send(:mentioned_users).map(&:id) },
        "to_s" => outcome { message.body.to_s.to_s },
        "canonical" => outcome { message.body&.body_before_type_cast && ActionText::Content.new(message.body.body_before_type_cast).to_html }
      }
    end
  end
end

File.write(OUTPUT, JSON.pretty_generate({ "generated_by" => "tests/fixtures/richtext/generate_seed.rb", "request_host" => HOST, "cases" => cases }) + "\n")
puts "Wrote #{cases.size} cases to #{OUTPUT}"
