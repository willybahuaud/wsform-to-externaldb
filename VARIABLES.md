# Variables in the column mapping

Each mapped column gets a template: plain text, WS Form variables, or both. The template is parsed by WS Form itself, so every WS Form variable works. Their reference lives in the [WS Form documentation](https://wsform.com/knowledgebase/variables/), and the variable picker in the editor lists them.

```
Column        Template
------------  ----------------------------------------------
email         #field(25)
full_name     #field(12) #field(13)
created_on    #blog_date_custom(Y-m-d)
summary       Sent by #field(12) on #blog_date_custom(d/m/Y)
              Phone: #field(14)
priority      #if(#field(10) == "urgent")high#endif
```

## How values are built

- The template is parsed as `text/plain`: no HTML escaping, and line breaks are kept, the ones typed in the template as well as the ones in a textarea.
- If a visitor types something that looks like a WS Form variable, WS Form neutralises it. The plugin then stores the text exactly as typed: `#post_meta(x)` stays `#post_meta(x)`.
- An empty result becomes `NULL` in a nullable column that is not a text column.
- Text in a numeric column, or a value too long for its column, makes the insert fail. See "When it fails" in the README.

The `ws_form_db_sanitize_value` filter receives each value just before insert:

```php
/**
 * Stores e-mail addresses in lower case.
 *
 * @param string $value    Parsed value.
 * @param string $template Template written in the mapping.
 * @param object $submit   WS Form submission.
 * @return string
 */
function my_prefix_lowercase_emails( $value, $template, $submit ) {
	return is_email( $value ) ? strtolower( $value ) : $value;
}
add_filter( 'ws_form_db_sanitize_value', 'my_prefix_lowercase_emails', 10, 3 );
```

## Variables added by the plugin

These variables read WordPress data from an ID. The typical case is a hidden field that holds the ID of a post, a product or a term, while the external table needs its title.

| Variable | Returns |
|----------|---------|
| `#get_post_title(ID)` | Post title |
| `#get_post_field(ID, field)` | One post field, see the list below |
| `#get_post_meta(ID, key)` | Post meta value. Arrays are returned as JSON |
| `#get_term_name(ID)` | Term name |
| `#get_term_name(ID, taxonomy)` | Term name, only if the term belongs to that taxonomy |
| `#get_user_display_name(ID)` | Display name of a user who has published posts |

The ID can come from a field:

```
#get_post_title(#field(12))
#get_post_field(#field(12), post_name)
#get_term_name(#field(30), product_cat)
```

Fields allowed in `#get_post_field()`: `post_title`, `post_content`, `post_excerpt`, `post_name`, `post_type`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`, `post_parent`, `menu_order`, `guid`.

### Limits, and why

When the ID comes from a field, the visitor chooses it. These variables therefore only return what is already public:

- posts that are published, not password-protected, and of a public post type;
- post meta whose key does not start with `_` (WordPress's protected meta);
- terms of a public taxonomy;
- display names of users who have published at least one post, so authors only, not subscribers or customers.

Anything else returns an empty string.

They are also resolved **only in this action's mapping**, and only in the template you write. A visitor who types `#get_post_meta(1, secret)` in a field gets that text stored as is. They do not exist in WS Form's other actions (e-mails, messages) and do not appear in its variable picker.
