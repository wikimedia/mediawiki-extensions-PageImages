-- DB schema for PageImages extension
CREATE TABLE /*_*/page_images(
  -- Key to page.page_id
  pi_page int unsigned NOT NULL PRIMARY KEY,
  -- Title of page thumbnail image
  pi_thumbnail varchar(255) binary NOT NULL,
  -- Number of "valid" images on page
  pi_images smallint NOT NULL,
  -- Total score of all images on page
  pi_total_score int NOT NULL,
  -- Record version in case scoring system changes
  pi_version tinyint NOT NULL
)/*$wgTableOptions*/;
