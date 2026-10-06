# Dumps Marcel's tables and I18n's transliteration approximations as PHP for campfire-symfony
# (adapted from once-campfire-rust reference-tools/storage/dump_tables.rb). Output goes to
# src/Storage/Marcel/MarcelTables.php (marcel) and src/Storage/Approximations.php (approximations).
require "marcel"
require "i18n"

def php_bytes(string)
  "\"" + string.b.bytes.map { |byte|
    if byte >= 0x20 && byte < 0x7f && byte != 0x22 && byte != 0x5c && byte != 0x24
      byte.chr
    else
      format("\\x%02x", byte)
    end
  }.join + "\""
end

def php_str(string) = php_bytes(string)

def php_matches(matches)
  "[" + matches.map { |offset, value, children|
    range_start, range_end = offset.is_a?(Range) ? [ offset.begin, offset.end.to_s ] : [ offset, "null" ]
    children_code = children ? php_matches(children) : "[]"
    value_code = value ? php_bytes(value) : "null"
    "[#{range_start}, #{range_end}, #{value_code}, #{children_code}]"
  }.join(", ") + "]"
end

def header(ns, cls, sources, doc)
  puts "<?php"
  puts
  puts "declare(strict_types=1);"
  puts
  puts "namespace #{ns};"
  puts
  puts "// @generated from #{sources} in the reference image (campfire-reference:app). Do not edit by hand."
  puts
  puts "/**"
  doc.each { puts " * #{_1}" }
  puts " *"
  puts " * @internal"
  puts " */"
  puts "final class #{cls}"
  puts "{"
end

case ARGV.first
when "marcel"
  header "App\\Storage\\Marcel", "MarcelTables", "marcel #{Marcel::VERSION}", [ "Marcel's EXTENSIONS, TYPE_EXTS, TYPE_PARENTS and MAGIC tables (after marcel/mime_type/definitions.rb)." ]
  puts "    /** `Marcel::EXTENSIONS`: extension => type. */"
  puts "    public const array EXTENSIONS = ["
  Marcel::EXTENSIONS.sort.each { |ext, type| puts "        #{php_str(ext)} => #{php_str(type)}," }
  puts "    ];"
  puts
  puts "    /** `Marcel::TYPE_EXTS`: type => extensions, in table order. */"
  puts "    public const array TYPE_EXTS = ["
  Marcel::TYPE_EXTS.sort.each { |type, exts| puts "        #{php_str(type)} => [#{exts.map { php_str(_1) }.join(", ")}]," }
  puts "    ];"
  puts
  puts "    /** `Marcel::TYPE_PARENTS`: type => parent types. */"
  puts "    public const array TYPE_PARENTS = ["
  Marcel::TYPE_PARENTS.sort.each { |type, parents| puts "        #{php_str(type)} => [#{parents.map { php_str(_1) }.join(", ")}]," }
  puts "    ];"
  puts
  puts "    /** `Marcel::MAGIC` in lookup order: [type, [[offset, rangeEnd|null, value|null, children], ...]]. */"
  puts "    public const array MAGIC = ["
  Marcel::MAGIC.each { |type, matches| puts "        [#{php_str(type)}, #{php_matches(matches)}]," }
  puts "    ];"
  puts "}"
when "approximations"
  header "App\\Storage", "Approximations", "i18n #{I18n::VERSION}", [ "`I18n::Backend::Transliterator::HashTransliterator::DEFAULT_APPROXIMATIONS`: character => ASCII." ]
  puts "    public const array MAP = ["
  I18n::Backend::Transliterator::HashTransliterator::DEFAULT_APPROXIMATIONS.sort.each do |char, ascii|
    puts "        #{php_str(char)} => #{php_str(ascii)},"
  end
  puts "    ];"
  puts "}"
end
