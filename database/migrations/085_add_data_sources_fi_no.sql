-- The lights of the national open services, read by the lighthouse import tool:
-- Finland (Väylävirasto, open WFS, CC BY 4.0) and Norway (Kystverket, navigation installations WFS, NLOD 2.0).
INSERT INTO data_source (code, name, url, license, license_url, attribution) VALUES
('fi', 'Väylävirasto (Finland)', 'https://vayla.fi/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/',
 'Väylävirasto (Finnish Transport Infrastructure Agency), Väylätiedot open data, not for navigation'),
('no', 'Kystverket (Norway)', 'https://www.kystverket.no/', 'NLOD 2.0 (Norwegian Licence for Open Government Data)', 'https://data.norge.no/nlod/en/2.0',
 'Kystverket (Norwegian Coastal Administration), navigation installations, not for navigation');
