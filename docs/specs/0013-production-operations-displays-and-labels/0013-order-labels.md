# Order labels

## Decision

Use direct browser printing for the normal manual workflow. RMS renders a fixed size, price free print page from the current protected order projection. The authenticated operator opens it from the order labels page and the browser on that device launches the operating system print dialog.

The cloud server does not need network access to the printer. The printer is selected locally in the browser print dialog, so RMS may be hosted on a VM while the operator prints from any authorized phone, tablet, or computer with access to a local printer. A terminal, device token, activation, agent heartbeat, or background task is not required for this manual path.

The existing POS queue and local agent remain available only as an optional unattended printing path. They must not gate or complicate manual printing.

## Default label

The label uses a single column hierarchy designed for narrow stock:

1. Layla Kitchen and the order number in the largest text.
2. Service date and time.
3. Customer name.
4. Delivery destination or pickup marker.
5. Item quantity followed by a shortened item description, one line per item where possible.
6. Copy number when more than one copy was requested.
7. A compact QR or Code 128 value containing only the order type and internal order ID.

Prices, discounts, invoice state, payment method, and phone number are excluded. Long text wraps or is shortened according to a deterministic rule. The preview warns when content exceeds the selected fixed media height. Continuous media computes a bounded page height from the actual lines and leaves the printer driver to cut after the page.

## Print actions

The first release supports one order print and one date batch print. Each click opens a new fixed size page and invokes the normal browser print dialog. It does not print automatically on payment, invoice, or order creation.

The default is one label per order. The operator may choose a small bounded copy count for orders split across packages. Each physical copy carries its copy number. A batch skips cancelled orders. RMS does not claim a browser label was physically printed because the operator can cancel the operating system dialog.

## Browser print boundary

The print route rechecks the actor's permission, source branch, label format branch, supported source type, cancellation state, dimensions, and bounded copy count. The projection excludes prices, discounts, payment data, invoice data, and phone numbers. The response creates no label record, print job, order mutation, or finance mutation.

Browser printing is deliberately user controlled. Reopening a label is simply another print action, and physical output remains the operator's responsibility. If silent printing is enabled later, the existing queue retains its separate acknowledgement and duplicate protection rules.

## Printer profiles

Profiles are configured in RMS Settings and supply the label format: company, branch, name, department, resolution, media type, width, height or continuous bounds, and default copies. Existing queue fields may remain on the record for compatibility, but their activation and verification state do not gate browser printing.

The administrator downloads one generated Windows setup script from the profile. It installs a native PowerShell agent as a system startup task, stores a token limited to print endpoints and one device, and creates the local queue allowlist from the profile. It downloads the pinned portable PDF renderer from its official HTTPS origin and verifies its checksum before use. The operator does not install Python or Bash, enter credentials, copy tokens, choose terminal IDs, or edit JSON.

A server profile cannot make the agent print to a queue outside the generated allowlist. Hardware changes invalidate verification and require a new setup download and physical test. Generating a replacement setup rotates the prior device token while the profile is inactive.

## Hardware adaptation

Brother QL 820NWB supports a 58 mm maximum printing width, 300 dpi output, USB, Wi Fi, Ethernet, Bluetooth, and an automatic cutter. The installed DK roll still decides the actual usable page dimensions.

The supplied BIXOLON name must be verified from the physical label. Official material was found for SLP DX220, which supports direct thermal stock up to 60 mm wide, with different printable widths and resolutions for DX220 and DX223. The build must not choose a resolution or printable width from the family name alone.

Both devices use their official Windows driver, USB, and fixed 57 mm by 37 mm label stock. RMS renders that exact PDF page. Manufacturer command languages remain adapter options only if driver printing proves unreliable during the physical test.
