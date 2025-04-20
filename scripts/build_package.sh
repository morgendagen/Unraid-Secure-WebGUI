#!/bin/bash

PLUGIN_NAME="ACME"
ACME_VERSION="3.1.0"

if [[ ! -v VERSION ]]; then
    echo "VERSION is not set"
    exit 1
fi

ARCHIVE_PATH="${PWD}/archive"
TMP_PATH=/tmp/tmp.$(( $RANDOM * 19318203981230 + 40 ))
PLUGIN_PATH=${TMP_PATH}/usr/local/emhttp/plugins/${PLUGIN_NAME}

mkdir -p $TMP_PATH
mkdir -p $ARCHIVE_PATH

# Copy sources

cp -r src/* $TMP_PATH/

# Extract and move acmesh into place

cd $TMP_PATH
mkdir -p ${TMP_PATH}/usr/share/ACME
wget --no-check-certificate -O acmesh-${ACME_VERSION}.tar.gz https://github.com/acmesh-official/acme.sh/archive/refs/tags/${ACME_VERSION}.tar.gz
tar xf acmesh-${ACME_VERSION}.tar.gz
mv acme.sh-${ACME_VERSION} ${TMP_PATH}/usr/share/ACME/acme.sh
rm acmesh-${ACME_VERSION}.tar.gz

# Extract dnsinfo, courtesy of https://github.com/yurt-page/acmesh-parse-dnsapi-info

echo -n "" > ${PLUGIN_PATH}/dnsapi_info.txt
for f in usr/share/ACME/acme.sh/dnsapi/dns_*.sh
do
  filename=$(basename -- "$f")
  dns_api="${filename%.*}"
  if [ "$dns_api" = 'dns_myapi' ]; then
   continue
  fi
  echo "$dns_api" >> ${PLUGIN_PATH}/dnsapi_info.txt
  dns_api_info_var="${dns_api}_info"
  # shellcheck source=./dnsapi/dns_*.sh
  . "$f"
  info=""
  eval info=\$$dns_api_info_var
  #echo "$info"
  # remove meta fields
  echo "$info" | sed '/^Issues:/d' | sed '/^Author:/d' | sed '/^Docs:/d' >> ${PLUGIN_PATH}/dnsapi_info.txt
done

# Create package

find .
makepkg -l y -c y ${ARCHIVE_PATH}/${PLUGIN_NAME}-${VERSION}-noarch-1.txz
#ls -lt > ${ARCHIVE_PATH}/${PLUGIN_NAME}-${VERSION}.txz

# Calculate md5 checksum

#MD5=`md5 -q ${ARCHIVE_PATH}/${PLUGIN_NAME}-${VERSION}.txz`
#echo -n $MD5 > ${ARCHIVE_PATH}/${PLUGIN_NAME}-${VERSION}.md5
#echo "MD5: $MD5"

# Cleanup

rm -rf $TMP_PATH
