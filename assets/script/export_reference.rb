# Runs inside the reference app (bin/rails runner, production, after assets:precompile) and
# exports what the Symfony port needs from it. Invoked by assets/script/revendor.
#
#   $OUT/assets/gems/<gem>/<path>     every gem-provided directory on the Propshaft load path,
#                                     copied verbatim, plus each gem's license file
#   $OUT/assets/PROVENANCE.gems.md    gem, version, source, path and sha256 of every vendored file
#   $OUT/tests/fixtures/rails/assets/ load_path.txt, manifest.json (the precompiled manifest),
#                                     compiled_sha256.json, compiled/*.css (url()s rewritten),
#                                     stylesheet_link_tag_all.html,
#                                     javascript_importmap_tags.html, link_header.txt
require "digest"
require "fileutils"
require "json"

out = Pathname.new(ENV.fetch("OUT"))
gems_dir = out.join("assets/gems")
fixtures = out.join("tests/fixtures/rails/assets")
FileUtils.rm_rf(gems_dir)
FileUtils.mkdir_p([ gems_dir, fixtures ])

def files_under(dir)
  Dir.glob("**/*", File::FNM_DOTMATCH, base: dir).sort
    .reject { |f| File.basename(f).start_with?(".") }
    .select { |f| File.file?(File.join(dir, f)) }
end

def gem_for(path)
  Gem.loaded_specs.values.select { |spec| path.start_with?(spec.full_gem_path + "/") }.max_by { |spec| spec.full_gem_path.length }
end

def source_for(spec)
  spec.source.is_a?(Bundler::Source::Git) ? "#{spec.source.uri}@#{spec.source.revision}" : "rubygems"
end

load_path = []
provenance = []
vendored = {}

Rails.application.assets.load_path.paths.each do |path|
  path = path.to_s
  root = Rails.root.to_s + "/"

  if path.start_with?(root)
    load_path << "reference:#{path.delete_prefix(root)}"
  else
    spec = gem_for(path) or raise "No gem owns asset path #{path}"
    vendored[spec.name] = spec
    rel = path.delete_prefix(spec.full_gem_path + "/")
    load_path << "gems:#{spec.name}/#{rel}"

    files_under(path).each do |file|
      dest = gems_dir.join(spec.name, rel, file)
      FileUtils.mkdir_p(dest.dirname)
      FileUtils.cp(File.join(path, file), dest)
      provenance << [ spec.name, spec.version.to_s, source_for(spec), "#{rel}/#{file}", Digest::SHA256.file(File.join(path, file)).hexdigest ]
    end
  end
end

vendored.each_value do |spec|
  licenses = Dir.glob("{MIT-LICENSE,LICENSE,LICENSE.*,LICENCE,COPYING}", base: spec.full_gem_path)
  raise "No license file in #{spec.name}" if licenses.empty?
  licenses.each { |license| FileUtils.cp(File.join(spec.full_gem_path, license), gems_dir.join(spec.name, license)) }
end

File.write(fixtures.join("load_path.txt"), load_path.join("\n") + "\n")

File.open(out.join("assets/PROVENANCE.gems.md"), "w") do |md|
  md.puts "| Gem | Version | Source | Path | SHA-256 |"
  md.puts "|---|---|---|---|---|"
  provenance.each { |row| md.puts "| #{row.join(" | ")} |" }
end

assets_dir = Rails.public_path.join("assets")
FileUtils.cp(assets_dir.join(".manifest.json"), fixtures.join("manifest.json"))
compiled = files_under(assets_dir.to_s).to_h { |f| [ f, Digest::SHA256.file(assets_dir.join(f)).hexdigest ] }
File.write(fixtures.join("compiled_sha256.json"), JSON.pretty_generate(compiled) + "\n")
FileUtils.rm_rf(fixtures.join("compiled"))
FileUtils.mkdir_p(fixtures.join("compiled"))
compiled.each_key { |f| FileUtils.cp(assets_dir.join(f), fixtures.join("compiled")) if f.end_with?(".css") && !f.include?("/") }

# The layout's asset tags, rendered by the real helpers in a real controller request.
probe = Class.new(ActionController::Base) do
  def self.name = "ProbeController"
  def show
    render inline: %(<%= stylesheet_link_tag :all, "data-turbo-track": "reload" %>\n<%= javascript_importmap_tags %>)
  end
end
_, headers, body = probe.action(:show).call(Rack::MockRequest.env_for("http://example.com/probe"))
html = +""
body.each { |chunk| html << chunk }
css, js = html.split("\n<script type=\"importmap\"", 2)
File.write(fixtures.join("stylesheet_link_tag_all.html"), css)
File.write(fixtures.join("javascript_importmap_tags.html"), "<script type=\"importmap\"" + js)
File.write(fixtures.join("link_header.txt"), headers["link"].to_s)

puts "Exported #{provenance.size} vendored files and #{compiled.size} compiled assets"
